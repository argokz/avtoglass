<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ApplyPaymentCallback;
use App\Domain\Shared\Outbox\Outbox;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessOutboxEvent;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\CallbackResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_schema_cannot_close_its_script_element(): void
    {
        $name = '</script><script>window.auditInjected=true</script>';
        $product = Product::factory()->create(['name' => $name]);
        $response = $this->get(route('catalog.product', $product))->assertOk();
        $response->assertDontSee($name, false);
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $response->getContent(), $matches);
        $schemas = array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
        $schema = collect($schemas)->firstWhere('@type', 'Product');
        $this->assertSame($name, $schema['name']);
    }

    public function test_one_tiyn_underpayment_does_not_confirm_an_order(): void
    {
        $payment = Payment::factory()->for(Order::factory())->create(['amount' => '100.00', 'status' => PaymentStatus::Pending]);
        $accepted = app(ApplyPaymentCallback::class)->handle($payment, new CallbackResult(
            token: $payment->token, status: PaymentStatus::Succeeded, amount: 99.99,
        ));
        $this->assertFalse($accepted);
        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
    }

    public function test_matching_fractional_payment_is_accepted(): void
    {
        $payment = Payment::factory()->for(Order::factory())->create(['amount' => '100.29', 'status' => PaymentStatus::Pending]);
        $this->assertTrue(app(ApplyPaymentCallback::class)->handle($payment, new CallbackResult(
            token: $payment->token, status: PaymentStatus::Succeeded, amount: 100.29,
        )));
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_outbox_recovers_an_expired_worker_lease_only(): void
    {
        Queue::fake();
        $make = fn () => DB::transaction(fn () => app(Outbox::class)->record('order.announced.v1', 'order', '1', ['order_id' => 1]));
        $expired = $make();
        $expired->forceFill(['status' => 'processing', 'attempts' => 1, 'available_at' => now()->subMinute()])->save();
        $active = $make();
        $active->forceFill(['status' => 'processing', 'attempts' => 1, 'available_at' => now()->addMinutes(5)])->save();
        $exhausted = $make();
        $exhausted->forceFill(['status' => 'failed', 'attempts' => 10])->save();
        $this->artisan('ordering:process-outbox')->assertSuccessful();
        Queue::assertPushed(ProcessOutboxEvent::class, fn ($job) => $job->outboxId === $expired->id);
        Queue::assertPushed(ProcessOutboxEvent::class, 1);
        $this->assertSame('processing', $active->fresh()->status);
        $this->assertSame('failed', $exhausted->fresh()->status);
    }

    public function test_preview_identity_is_not_exposed_outside_preview(): void
    {
        $this->get('/preview/health')->assertNotFound();
    }

    public function test_vehicle_picker_rejects_a_model_from_another_brand(): void
    {
        $brand = CarBrand::factory()->create();
        $model = CarModel::factory()->create();
        $this->post('/pick', ['brand' => $brand->id, 'model' => $model->id])->assertSessionHasErrors('model');
    }

    public function test_inactive_offer_cannot_be_selected_on_the_product_page(): void
    {
        $offer = Offer::factory()->create(['is_active' => false]);
        $this->get(route('catalog.product', $offer->product))->assertOk()
            ->assertDontSee('name="picked_offer" value="'.$offer->id.'"', false);
    }

    public function test_account_history_is_paginated_and_excludes_foreign_orders(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['role' => 'customer', 'customer_id' => $customer->id]);
        Order::factory()->count(21)->create(['customer_id' => $customer->id]);
        $foreign = Order::factory()->create();
        $this->actingAs($user)->get('/kabinet')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 20 && $orders->total() === 21)
            ->assertDontSee($foreign->number);
        $this->get('/kabinet?page=2')->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 1);
    }
}
