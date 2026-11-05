<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Ordering\Data\ProposalComparison;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\OrderProposal;
use App\Domain\Ordering\OrderProposalService;
use App\Domain\Ordering\PurchaseCommands;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\ProposalForm;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsQuoteFixtures;
use Tests\TestCase;

final class OrderProposalJourneyTest extends TestCase
{
    use BuildsQuoteFixtures, RefreshDatabase;

    public function test_manager_resolves_delivery_through_the_crm_form_without_changing_the_order_before_acceptance(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version,
            ['fulfillment' => ['type' => 'delivery', 'city' => 'Караганда', 'address' => 'Исходный адрес 1']]);
        $quote = $this->prepare($f);
        $submitted = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'review-receiving', $quote['id'], $f['draft']->version, 'submit_for_review');
        $order = Order::query()->findOrFail($submitted->data['order_id']);
        $original = $order->purchase_snapshot;
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($manager)->test(ListOrders::class)->callTableAction('proposal', $order, [
            'items' => [[...$f['item'], 'offer_id' => (string) $f['offer']->id, 'quantity' => '3']],
            'fulfillment' => ['type' => 'pickup', 'city' => 'Караганда', 'service_point_id' => (string) $f['point']->id],
            'payment_method' => 'cash',
        ])->assertHasNoTableActionErrors();
        $proposal = OrderProposal::query()->where('order_id', $order->id)->sole();
        $this->assertSame($original, $order->refresh()->purchase_snapshot);
        $this->assertSame('review_required', $order->purchase_state);
        $this->assertSame('pickup', $proposal->snapshot['fulfillment']['type']);
        $this->assertNull($proposal->snapshot['fulfillment']['address'] ?? null);
        $rows = collect(ProposalComparison::rows($original, $proposal->snapshot, 'ru'))->keyBy('label');
        $this->assertSame(['label' => 'Получение', 'before' => 'Доставка', 'after' => 'Самовывоз', 'changed' => true], $rows['Получение']);
        $this->assertSame('Исходный адрес 1', $rows['Адрес']['before']);
        $this->assertSame('—', $rows['Адрес']['after']);
        $this->assertDatabaseCount('stock_reservations', 0);
        app(OrderProposalService::class)->accept($f['buyer'], $order->public_token, $proposal->id);
        $this->assertSame('pickup', $order->refresh()->fulfillment->value);
        $this->assertNull($order->address);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_form_converts_service_local_time_and_removes_hidden_shipping_details(): void
    {
        $f = $this->fixture();
        $f['point']->update(['timezone' => 'Asia/Qyzylorda']);
        $data = ['items' => [$f['item']], 'payment_method' => 'cash', 'fulfillment' => [
            'type' => 'installation', 'city' => 'Караганда', 'address' => 'Старый адрес', 'service_point_id' => (string) $f['point']->id,
            'slot_start' => '2026-09-21 09:00:00', 'slot_end' => '2026-09-21 11:30:00',
        ]];
        $changes = ProposalForm::changes($data);
        $this->assertSame('2026-09-21T09:00:00+05:00', $changes['fulfillment']['slot_start']);
        $this->assertNull($changes['fulfillment']['address']);
        $data['fulfillment']['type'] = 'delivery';
        $changes = ProposalForm::changes($data);
        $this->assertNull($changes['fulfillment']['slot_start']);
        $this->assertNull($changes['fulfillment']['slot_end']);
        $this->assertNull($changes['fulfillment']['service_point_id']);
    }

    public function test_comparison_keeps_the_accepted_point_and_service_price_from_the_snapshot(): void
    {
        $snapshot = ['items' => [], 'fulfillment' => ['type' => 'installation', 'city' => 'Караганда',
            'point' => ['name' => 'Исходный сервис', 'address' => 'Адрес 1', 'timezone' => 'Asia/Qyzylorda'],
            'slot_start' => '2026-09-21T04:00:00+00:00', 'slot_end' => '2026-09-21T06:30:00+00:00'],
            'payment_method' => 'cash', 'services_total' => ['amount_minor' => 1500025, 'currency' => 'KZT']];
        $rows = collect(ProposalComparison::rows($snapshot, $snapshot, 'kk'))->keyBy('label');
        $this->assertSame('Исходный сервис Адрес 1', $rows['Алу орны']['before']);
        $this->assertSame('15 000,25 KZT', $rows['Жұмыстар']['after']);
        $this->assertStringContainsString('09:00', $rows['Орнату уақыты']['before']);
        $this->assertCount(0, $rows->where('changed', true));
    }
}
