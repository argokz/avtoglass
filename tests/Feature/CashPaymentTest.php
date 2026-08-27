<?php

namespace Tests\Feature;

use App\Actions\AcceptCashPayment;
use App\Analytics\SalesReport;
use App\Enums\AssignmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Field\Pages\MyAssignments;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Оплата при получении.
 *
 * Отличается от карты тем, что деньги принимает человек и держит их у себя.
 * Отсюда и проверки: кто принял, не больше остатка, и попадают ли наличные
 * в выручку наравне с онлайном.
 */
class CashPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function order(float $amount = 30000): Order
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => $amount, 'quantity' => 1]);

        return $order->fresh();
    }

    private function action(): AcceptCashPayment
    {
        return app(AcceptCashPayment::class);
    }

    // ------------------------------------------------------------------
    // остаток
    // ------------------------------------------------------------------

    public function test_остаток_равен_сумме_пока_не_платили(): void
    {
        $this->assertSame(30000.0, $this->order(30000)->outstanding());
    }

    /** Клиент мог внести часть картой, а остаток отдать курьеру. */
    public function test_остаток_учитывает_уже_внесённое(): void
    {
        $order = $this->order(30000);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 10000,
        ]);

        $this->assertSame(20000.0, $order->fresh()->outstanding());
    }

    public function test_неудачный_платёж_остаток_не_уменьшает(): void
    {
        $order = $this->order(30000);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Failed,
            'amount' => 30000,
        ]);

        $this->assertSame(30000.0, $order->fresh()->outstanding());
    }

    // ------------------------------------------------------------------
    // приём денег
    // ------------------------------------------------------------------

    public function test_приём_наличных_записывает_кто_принял(): void
    {
        $order = $this->order(30000);
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);

        $payment = $this->action()->handle($order, 30000, $courier);

        $this->assertNotNull($payment);
        $this->assertSame('cash', $payment->provider);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame($courier->id, $payment->received_by);
        $this->assertNotNull($payment->paid_at);
        $this->assertTrue($payment->isCash());
    }

    public function test_полная_оплата_подтверждает_заказ(): void
    {
        $order = $this->order(30000);

        $this->action()->handle($order, 30000, User::factory()->courier()->create());

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame(0.0, $order->fresh()->outstanding());
    }

    /** Частичная оплата — ещё не сделка, двигать по ней воронку нельзя. */
    public function test_частичная_оплата_не_подтверждает_заказ(): void
    {
        $order = $this->order(30000);

        $this->action()->handle($order, 10000, User::factory()->courier()->create());

        $order->refresh();

        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(20000.0, $order->outstanding());
    }

    public function test_остаток_можно_довнести_вторым_платежом(): void
    {
        $order = $this->order(30000);
        $courier = User::factory()->courier()->create();

        $this->action()->handle($order, 10000, $courier);
        $this->action()->handle($order->fresh(), 20000, $courier);

        $this->assertSame(0.0, $order->fresh()->outstanding());
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    /**
     * Переплата — это либо опечатка, либо чужие деньги через заказ.
     * И то и другое лучше остановить на месте.
     */
    public function test_больше_остатка_принять_нельзя(): void
    {
        $order = $this->order(30000);

        $payment = $this->action()->handle($order, 50000, User::factory()->courier()->create());

        $this->assertNull($payment);
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_оплаченный_заказ_повторно_не_принимает(): void
    {
        $order = $this->order(30000);
        $courier = User::factory()->courier()->create();

        $this->action()->handle($order, 30000, $courier);
        $second = $this->action()->handle($order->fresh(), 1000, $courier);

        $this->assertNull($second);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_отменённый_заказ_денег_не_принимает(): void
    {
        $order = $this->order(30000);
        $order->transitionTo(OrderStatus::Cancelled);

        $this->assertNull(
            $this->action()->handle($order->fresh(), 30000, User::factory()->courier()->create())
        );
    }

    public function test_нулевая_и_отрицательная_сумма_отклоняются(): void
    {
        $order = $this->order(30000);
        $courier = User::factory()->courier()->create();

        $this->assertNull($this->action()->handle($order, 0, $courier));
        $this->assertNull($this->action()->handle($order, -500, $courier));
    }

    public function test_приём_наличных_попадает_в_журнал(): void
    {
        $order = $this->order(30000);
        $courier = User::factory()->courier()->create(['name' => 'Курьер Ерлан']);

        $this->action()->handle($order, 30000, $courier);

        $entry = $order->interactions()->where('body', 'like', '%наличными%')->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('Курьер Ерлан', $entry->body);
    }

    // ------------------------------------------------------------------
    // экран курьера
    // ------------------------------------------------------------------

    public function test_курьер_принимает_оплату_со_своего_экрана(): void
    {
        $courier = User::factory()->courier()->create();
        $order = $this->order(25000);

        $assignment = Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(12, 0),
        ]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->call('acceptCash', $assignment->id, '25000');

        $payment = $order->fresh()->payments()->sole();

        $this->assertSame($courier->id, $payment->received_by);
        // Наряд сохраняется: по нему потом сверяют, кто и с какого адреса привёз
        $this->assertSame($assignment->id, $payment->assignment_id);
    }

    /** Чужой наряд не тронуть, даже зная его номер. */
    public function test_чужой_наряд_оплату_не_принимает(): void
    {
        $courier = User::factory()->courier()->create();
        $other = User::factory()->courier()->create();

        $assignment = Assignment::factory()->create([
            'order_id' => $this->order()->id,
            'assignee_id' => $other->id,
        ]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->call('acceptCash', $assignment->id, '30000');
    }

    public function test_экран_показывает_сколько_получить(): void
    {
        $courier = User::factory()->courier()->create();
        $order = $this->order(25000);

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(12, 0),
        ]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->assertSee('Получить')
            ->assertSee('25 000');
    }

    /** По оплаченному заказу денег брать не надо, и это должно быть видно. */
    public function test_по_оплаченному_заказу_экран_не_просит_денег(): void
    {
        $courier = User::factory()->courier()->create();
        $order = $this->order(25000);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 25000,
        ]);

        Assignment::factory()->type(AssignmentType::Delivery)->create([
            'order_id' => $order->id,
            'assignee_id' => $courier->id,
            'scheduled_at' => today()->setTime(12, 0),
        ]);

        Livewire::actingAs($courier)
            ->test(MyAssignments::class)
            ->assertSee('деньги брать не нужно');
    }

    // ------------------------------------------------------------------
    // отчётность
    // ------------------------------------------------------------------

    /** Ради этого всё и делалось: наличные должны считаться выручкой. */
    public function test_наличные_попадают_в_выручку(): void
    {
        $order = $this->order(30000);

        $this->action()->handle($order, 30000, User::factory()->courier()->create());

        $this->assertSame(30000.0, SalesReport::forDays(7)->revenue());
    }

    public function test_наличные_и_карта_складываются_в_отчёте(): void
    {
        $cashOrder = $this->order(30000);
        $this->action()->handle($cashOrder, 30000, User::factory()->courier()->create());

        $cardOrder = $this->order(20000);
        Payment::factory()->for($cardOrder)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 20000,
            'paid_at' => now(),
        ]);

        $this->assertSame(50000.0, SalesReport::forDays(7)->revenue());
    }
}
