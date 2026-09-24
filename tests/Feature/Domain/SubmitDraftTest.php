<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\Outbox\OutboxEvent;
use App\Domain\Shared\PurchaseException;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Jobs\ProcessOutboxEvent;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsQuoteFixtures;
use Tests\TestCase;

class SubmitDraftTest extends TestCase
{
    use BuildsQuoteFixtures, RefreshDatabase;

    public function test_submit_for_review_creates_new_order_without_reserve_and_writes_outbox(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft(
            $f['buyer'], $f['draft']->id, 'submit-review', $quote['id'], $f['draft']->version, 'submit_for_review',
        );

        $this->assertFalse($result->replayed);
        $this->assertSame(201, $result->status);
        $this->assertSame('manager_review', $result->data['path']);
        $this->assertFalse($result->data['reserved']);
        $order = Order::query()->findOrFail($result->data['order_id']);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame('submitted', OrderDraft::query()->findOrFail($f['draft']->id)->status);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertSame('pending', OutboxEvent::query()->sole()->status);
        Queue::assertPushed(ProcessOutboxEvent::class);
        $replay = app(PurchaseCommands::class)->submitDraft(
            $f['buyer'], $f['draft']->id, 'submit-review', $quote['id'], $f['draft']->version, 'submit_for_review',
        );
        $this->assertTrue($replay->replayed);
        $this->assertSame($result->data['order_id'], $replay->data['order_id']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_confirm_retail_is_gated_when_auto_checkout_disabled(): void
    {
        config(['ordering.auto_checkout_enabled' => false]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);

        try {
            app(PurchaseCommands::class)->submitDraft(
                $f['buyer'], $f['draft']->id, 'confirm-off', $quote['id'], $f['draft']->version, 'confirm_retail',
            );
            $this->fail('Expected AUTO_CHECKOUT_DISABLED');
        } catch (PurchaseException $exception) {
            $this->assertSame('AUTO_CHECKOUT_DISABLED', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('active', OrderDraft::query()->findOrFail($f['draft']->id)->status);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_confirm_retail_reserves_fully_when_enabled(): void
    {
        Queue::fake();
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft(
            $f['buyer'], $f['draft']->id, 'confirm-on', $quote['id'], $f['draft']->version, 'confirm_retail',
        );

        $this->assertSame('auto_confirmed', $result->data['path']);
        $this->assertTrue($result->data['reserved']);
        $order = Order::query()->findOrFail($result->data['order_id']);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $reservation = StockReservation::query()->sole();
        $this->assertSame(ReservationStatus::Held, $reservation->status);
        $this->assertSame(3, $reservation->quantity);
        $this->assertSame($order->id, $reservation->order_id);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_confirm_retail_does_not_silently_downgrade_to_review(): void
    {
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture();
        $f['offer']->update(['auto_checkout_enabled' => false]);
        $quote = $this->prepare($f);
        $this->assertSame('review_required', $quote['readiness']);

        try {
            app(PurchaseCommands::class)->submitDraft(
                $f['buyer'], $f['draft']->id, 'confirm-review', $quote['id'], $f['draft']->version, 'confirm_retail',
            );
            $this->fail('Expected REVIEW_REQUIRED');
        } catch (PurchaseException $exception) {
            $this->assertSame('REVIEW_REQUIRED', $exception->errorCode);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('active', OrderDraft::query()->findOrFail($f['draft']->id)->status);
    }

    public function test_wholesale_confirm_retail_is_rejected(): void
    {
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture(true);
        $quote = $this->prepare($f);

        try {
            app(PurchaseCommands::class)->submitDraft(
                $f['buyer'], $f['draft']->id, 'wh-confirm', $quote['id'], $f['draft']->version, 'confirm_retail',
            );
            $this->fail('Expected REVIEW_REQUIRED');
        } catch (PurchaseException $exception) {
            $this->assertSame('REVIEW_REQUIRED', $exception->errorCode);
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_insufficient_stock_rolls_back_and_keeps_draft_active(): void
    {
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        // Hold all stock under another order so available becomes 0 after lock.
        $foreign = Order::factory()->create();
        StockReservation::create([
            'offer_id' => $f['offer']->id,
            'order_id' => $foreign->id,
            'order_item_id' => null,
            'quantity' => 10,
            'status' => ReservationStatus::Held,
        ]);

        try {
            app(PurchaseCommands::class)->submitDraft(
                $f['buyer'], $f['draft']->id, 'no-stock', $quote['id'], $f['draft']->version, 'confirm_retail',
            );
            $this->fail('Expected INSUFFICIENT_STOCK or QUOTE_CHANGED');
        } catch (PurchaseException $exception) {
            $this->assertContains($exception->errorCode, ['INSUFFICIENT_STOCK', 'QUOTE_CHANGED']);
        }
        $this->assertDatabaseCount('orders', 1); // only the foreign order
        $this->assertSame('active', OrderDraft::query()->findOrFail($f['draft']->id)->status);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_http_submit_route_requires_idempotency_key_and_creates_review_order(): void
    {
        Queue::fake();
        config(['ordering.api_enabled' => true]);
        $this->withSession(['ordering.guest_token' => str_repeat('a', 64)]);
        $f = $this->fixture();
        $quote = app(QuoteDraft::class)->prepare($f['buyer'], $f['draft']->id, $f['draft']->version)->jsonSerialize();

        $this->postJson('/api/v1/drafts/'.$f['draft']->id.'/submit', [
            'quote_id' => $quote['id'],
            'expected_version' => $f['draft']->version,
            'action' => 'submit_for_review',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/drafts/'.$f['draft']->id.'/submit', [
            'quote_id' => $quote['id'],
            'expected_version' => $f['draft']->version,
            'action' => 'submit_for_review',
        ], ['Idempotency-Key' => 'http-submit'])->assertCreated()
            ->assertJsonPath('data.path', 'manager_review');
        $this->assertDatabaseCount('orders', 1);
    }
}
