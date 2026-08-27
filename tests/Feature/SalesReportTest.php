<?php

namespace Tests\Feature;

use App\Analytics\SalesReport;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Отчёты по продажам.
 *
 * Отчёт легко незаметно завысить — достаточно сложить суммы заявок вместо
 * полученных денег, и цифры станут красивее ровно настолько, насколько плохо
 * работает отдел продаж. Поэтому проверяется именно то, что в выручку
 * не попадает.
 */
class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function paidOrder(float $amount, string $paidAt, OrderStatus $status = OrderStatus::Completed): Order
    {
        $order = Order::factory()->create([
            'status' => $status,
            'total_amount' => $amount,
            'created_at' => $paidAt,
        ]);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => $amount,
            'paid_at' => $paidAt,
        ]);

        return $order;
    }

    // ------------------------------------------------------------------
    // выручка
    // ------------------------------------------------------------------

    public function test_выручка_складывает_только_прошедшие_платежи(): void
    {
        $this->paidOrder(30000, '2026-09-10 10:00');

        // неоплаченная заявка на крупную сумму в выручку попасть не должна
        Order::factory()->create(['total_amount' => 500000, 'created_at' => '2026-09-11 10:00']);

        $this->assertSame(30000.0, SalesReport::forDays(30)->revenue());
    }

    public function test_неудачный_платёж_в_выручку_не_попадает(): void
    {
        $order = Order::factory()->create(['total_amount' => 40000, 'created_at' => '2026-09-10']);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Failed,
            'amount' => 40000,
            'paid_at' => null,
        ]);

        $this->assertSame(0.0, SalesReport::forDays(30)->revenue());
    }

    public function test_платежи_вне_периода_не_учитываются(): void
    {
        $this->paidOrder(30000, '2026-09-14 10:00');   // в неделю попадает
        $this->paidOrder(50000, '2026-08-01 10:00');   // старый

        $this->assertSame(30000.0, SalesReport::forDays(7)->revenue());
    }

    // ------------------------------------------------------------------
    // средний чек
    // ------------------------------------------------------------------

    /** Делить выручку на все заявки, включая неоплаченные, — занижать в разы. */
    public function test_средний_чек_считается_по_оплатам_а_не_по_заявкам(): void
    {
        $this->paidOrder(30000, '2026-09-10');
        $this->paidOrder(50000, '2026-09-11');

        // десять пустых заявок не должны утянуть средний чек вниз
        Order::factory()->count(10)->create(['created_at' => '2026-09-12']);

        $this->assertSame(40000.0, SalesReport::forDays(30)->averageCheck());
    }

    public function test_без_оплат_средний_чек_ноль_а_не_ошибка(): void
    {
        Order::factory()->count(3)->create(['created_at' => '2026-09-10']);

        $this->assertSame(0.0, SalesReport::forDays(30)->averageCheck());
    }

    // ------------------------------------------------------------------
    // конверсия
    // ------------------------------------------------------------------

    public function test_конверсия_считает_дошедших_до_сделки(): void
    {
        $this->paidOrder(30000, '2026-09-10', OrderStatus::Completed);
        Order::factory()->status(OrderStatus::Confirmed)->create(['created_at' => '2026-09-10']);
        Order::factory()->status(OrderStatus::New)->create(['created_at' => '2026-09-10']);
        Order::factory()->status(OrderStatus::New)->create(['created_at' => '2026-09-10']);

        $this->assertSame(50.0, SalesReport::forDays(30)->conversion());
    }

    /**
     * Отменённые остаются в знаменателе: иначе конверсия улучшается от того,
     * что менеджер отменяет неудобные заявки.
     */
    public function test_отменённые_не_выкидываются_из_конверсии(): void
    {
        $this->paidOrder(30000, '2026-09-10', OrderStatus::Completed);
        Order::factory()->status(OrderStatus::Cancelled)->count(3)->create(['created_at' => '2026-09-10']);

        $this->assertSame(25.0, SalesReport::forDays(30)->conversion());
    }

    public function test_без_заявок_конверсия_ноль_а_не_деление_на_ноль(): void
    {
        $this->assertSame(0.0, SalesReport::forDays(30)->conversion());
    }

    // ------------------------------------------------------------------
    // график
    // ------------------------------------------------------------------

    /** Дни без продаж должны быть нулями, иначе график врёт про рост. */
    public function test_дни_без_продаж_остаются_в_графике_нулями(): void
    {
        $this->paidOrder(30000, '2026-09-14 10:00');

        $byDay = SalesReport::forDays(6)->revenueByDay();

        $this->assertCount(7, $byDay);
        $this->assertSame(30000.0, $byDay['2026-09-14']);
        $this->assertSame(0.0, $byDay['2026-09-12']);
    }

    public function test_несколько_оплат_за_день_складываются(): void
    {
        $this->paidOrder(30000, '2026-09-14 10:00');
        $this->paidOrder(20000, '2026-09-14 18:00');

        $this->assertSame(50000.0, SalesReport::forDays(7)->revenueByDay()['2026-09-14']);
    }

    // ------------------------------------------------------------------
    // срезы
    // ------------------------------------------------------------------

    public function test_топ_товаров_считается_по_покупкам(): void
    {
        $order = $this->paidOrder(30000, '2026-09-10');
        $order->items()->create(['name' => 'Лобовое Camry', 'price' => 30000, 'quantity' => 1]);

        $second = $this->paidOrder(30000, '2026-09-11');
        $second->items()->create(['name' => 'Лобовое Camry', 'price' => 30000, 'quantity' => 1]);

        $third = $this->paidOrder(15000, '2026-09-12');
        $third->items()->create(['name' => 'Заднее Hilux', 'price' => 15000, 'quantity' => 1]);

        $top = SalesReport::forDays(30)->topProducts();

        $this->assertSame('Лобовое Camry', $top->first()->name);
        $this->assertSame(2, (int) $top->first()->orders_count);
    }

    public function test_источники_заявок_группируются(): void
    {
        Order::factory()->count(2)->create([
            'source' => OrderSource::ProductPage,
            'created_at' => '2026-09-10',
        ]);
        Order::factory()->create([
            'source' => OrderSource::Selection,
            'created_at' => '2026-09-10',
        ]);

        $sources = SalesReport::forDays(30)->bySource();

        $this->assertSame(2, (int) $sources['product_page']);
        $this->assertSame(1, (int) $sources['selection']);
    }
}
