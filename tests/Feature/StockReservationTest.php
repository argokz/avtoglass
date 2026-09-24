<?php

namespace Tests\Feature;

use App\Catalog\StockLedger;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Резерв стекла под заказ.
 *
 * До этого «стекло зарезервировано за вами» было надписью на странице
 * благодарности: остаток не менялся ни при оплате, ни при отгрузке, и одну
 * и ту же единицу можно было продать онлайн сколько угодно раз. Здесь
 * проверяется, что теперь за этой фразой стоит запись в базе.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    /** Заказ на одну позицию с настоящим предложением поставщика. */
    private function orderFor(int $stock, int $quantity = 1, float $price = 30000): Order
    {
        $offer = Offer::factory()->price((int) $price)->create(['stock_qty' => $stock]);

        $order = Order::factory()->create(['total_amount' => $price * $quantity]);
        $order->items()->create([
            'product_id' => $offer->product_id,
            'offer_id' => $offer->id,
            'name' => 'Лобовое стекло Camry',
            'price' => $price,
            'quantity' => $quantity,
        ]);

        return $order->fresh();
    }

    private function offerOf(Order $order): Offer
    {
        return Offer::findOrFail($order->items()->first()->offer_id);
    }

    // ------------------------------------------------------------------
    // резерв
    // ------------------------------------------------------------------

    public function test_подтверждение_заказа_резервирует_стекло(): void
    {
        $order = $this->orderFor(stock: 3);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(1, $order->reservations()->count());
        $this->assertSame(2, $this->offerOf($order)->available());
    }

    /**
     * Резерв не трогает складской остаток: стекло физически лежит на полке
     * до момента выдачи, иначе не сойдётся инвентаризация.
     */
    public function test_резерв_не_уменьшает_складской_остаток(): void
    {
        $order = $this->orderFor(stock: 3);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(3, (int) $this->offerOf($order)->stock_qty);
    }

    public function test_новая_заявка_ничего_не_резервирует(): void
    {
        $order = $this->orderFor(stock: 3);

        $this->assertSame(0, $order->reservations()->count());
        $this->assertSame(3, $this->offerOf($order)->available());
    }

    /** Заявка в работе — это ещё разговор, а не сделка. */
    public function test_взятая_в_работу_заявка_склад_не_держит(): void
    {
        $order = $this->orderFor(stock: 1);

        $order->transitionTo(OrderStatus::InProgress);

        $this->assertSame(1, $this->offerOf($order)->available());
    }

    public function test_повторное_подтверждение_не_удваивает_резерв(): void
    {
        $order = $this->orderFor(stock: 5);

        $order->transitionTo(OrderStatus::Confirmed);
        $this->ledger()->reserve($order->fresh());
        $this->ledger()->reserve($order->fresh());

        $this->assertSame(1, $order->reservations()->count());
        $this->assertSame(4, $this->offerOf($order)->available());
    }

    // ------------------------------------------------------------------
    // нехватка
    // ------------------------------------------------------------------

    /**
     * Заказ мог быть уже оплачен: откатывать подтверждение из-за склада —
     * значит взять деньги и оставить клиента ни с чем. Резервируем сколько
     * есть, а разницу показываем менеджеру.
     */
    public function test_при_нехватке_резервируется_сколько_есть(): void
    {
        $order = $this->orderFor(stock: 1, quantity: 3);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(1, (int) $order->reservations()->first()->quantity);
        $this->assertSame(0, $this->offerOf($order)->available());
    }

    public function test_частичный_резерв_попадает_в_журнал(): void
    {
        $order = $this->orderFor(stock: 1, quantity: 3);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertNotNull(
            $order->interactions()->where('body', 'like', '%Зарезервировано частично%')->first()
        );
    }

    public function test_при_нулевом_остатке_брони_нет_но_есть_запись(): void
    {
        $order = $this->orderFor(stock: 0);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(0, $order->reservations()->count());
        $this->assertNotNull(
            $order->interactions()->where('body', 'like', '%Нет на складе%')->first()
        );
    }

    /** Два заказа не могут поделить одну единицу. */
    public function test_второй_заказ_на_последнее_стекло_остаётся_без_резерва(): void
    {
        $first = $this->orderFor(stock: 1);
        $offer = $this->offerOf($first);

        $second = Order::factory()->create(['total_amount' => 30000]);
        $second->items()->create([
            'product_id' => $offer->product_id,
            'offer_id' => $offer->id,
            'name' => 'Лобовое стекло Camry',
            'price' => 30000,
            'quantity' => 1,
        ]);

        $first->transitionTo(OrderStatus::Confirmed);
        $second->fresh()->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(1, $first->reservations()->count());
        $this->assertSame(0, $second->reservations()->count());
    }

    /** Позиция без привязки к поставщику резерв не ломает. */
    public function test_позиция_без_предложения_пропускается(): void
    {
        $order = Order::factory()->create(['total_amount' => 30000]);
        $order->items()->create(['name' => 'Работы по установке', 'price' => 30000, 'quantity' => 1]);

        $order->fresh()->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(0, $order->reservations()->count());
    }

    // ------------------------------------------------------------------
    // снятие и списание
    // ------------------------------------------------------------------

    public function test_отмена_возвращает_стекло_в_продажу(): void
    {
        $order = $this->orderFor(stock: 3);

        $order->transitionTo(OrderStatus::Confirmed);
        $order->fresh()->transitionTo(OrderStatus::Cancelled);

        $this->assertSame(3, $this->offerOf($order)->available());
        $this->assertSame(ReservationStatus::Released, $order->reservations()->first()->status);
    }

    /**
     * Списание — единственное место, где уменьшается stock_qty:
     * до выдачи стекло всё ещё на складе.
     */
    public function test_выполненный_заказ_списывает_остаток(): void
    {
        $order = $this->orderFor(stock: 3);

        $order->transitionTo(OrderStatus::Confirmed);
        $order = $order->fresh();
        $order->transitionTo(OrderStatus::Scheduled);
        $order->fresh()->transitionTo(OrderStatus::Completed);

        $offer = $this->offerOf($order);

        $this->assertSame(2, (int) $offer->stock_qty);
        $this->assertSame(2, $offer->available());
        $this->assertSame(ReservationStatus::Consumed, $order->reservations()->first()->status);
    }

    // ------------------------------------------------------------------
    // срок брони
    // ------------------------------------------------------------------

    /** Половина заявок не доезжает до оплаты — их брони обязаны протухать. */
    public function test_бронь_по_неоплаченному_заказу_имеет_срок(): void
    {
        config(['catalog.reservation.unpaid_days' => 3]);

        $order = $this->orderFor(stock: 2);
        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertNotNull($order->reservations()->first()->expires_at);
    }

    /** Клиент заплатил — снимать с него бронь по таймеру нельзя. */
    public function test_бронь_по_оплаченному_заказу_бессрочна(): void
    {
        $order = $this->orderFor(stock: 2);

        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => $order->total_amount,
            'paid_at' => now(),
        ]);

        $order->fresh()->transitionTo(OrderStatus::Confirmed);

        $this->assertNull($order->reservations()->first()->expires_at);
    }

    public function test_просроченная_бронь_снимается_командой(): void
    {
        $order = $this->orderFor(stock: 2);
        $order->transitionTo(OrderStatus::Confirmed);

        $order->reservations()->update(['expires_at' => now()->subMinute()]);

        $this->artisan('stock:release-expired')->assertSuccessful();

        $this->assertSame(ReservationStatus::Released, $order->reservations()->first()->status);
        $this->assertSame(2, $this->offerOf($order)->available());
    }

    public function test_живая_бронь_командой_не_трогается(): void
    {
        $order = $this->orderFor(stock: 2);
        $order->transitionTo(OrderStatus::Confirmed);

        $this->artisan('stock:release-expired');

        $this->assertSame(ReservationStatus::Held, $order->reservations()->first()->status);
    }

    public function test_снятие_по_сроку_попадает_в_журнал(): void
    {
        $order = $this->orderFor(stock: 2);
        $order->transitionTo(OrderStatus::Confirmed);
        $order->reservations()->update(['expires_at' => now()->subMinute()]);

        $this->artisan('stock:release-expired');

        $this->assertNotNull(
            $order->interactions()->where('body', 'like', '%Резерв снят по сроку%')->first()
        );
    }

    // ------------------------------------------------------------------
    // витрина
    // ------------------------------------------------------------------

    /** Клиенту показывается свободный остаток, а не складской. */
    public function test_витрина_не_показывает_отложенное_другим(): void
    {
        $order = $this->orderFor(stock: 1);
        $offer = $this->offerOf($order);

        $this->assertSame(1, $offer->available());

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertSame(0, $offer->fresh()->available());
        $this->assertSame(1, StockReservation::held()->count());
    }

    public function test_карточка_считается_отсутствующей_когда_всё_зарезервировано(): void
    {
        $order = $this->orderFor(stock: 1);
        $product = $this->offerOf($order)->product;
        $product->refreshPricing();

        $this->assertTrue($product->fresh()->in_stock);

        $order->transitionTo(OrderStatus::Confirmed);

        $this->assertFalse($product->fresh()->in_stock);
    }

    public function test_списание_последней_единицы_снимает_наличие_с_карточки(): void
    {
        $order = $this->orderFor(stock: 1);
        $product = $this->offerOf($order)->product;

        $order->transitionTo(OrderStatus::Confirmed);
        $order = $order->fresh();
        $order->transitionTo(OrderStatus::Scheduled);
        $order->fresh()->transitionTo(OrderStatus::Completed);

        $this->assertFalse($product->fresh()->in_stock);
        $this->assertSame(0, (int) $this->offerOf($order)->stock_qty);
    }

    public function test_снятие_резерва_возвращает_карточку_в_наличие(): void
    {
        $order = $this->orderFor(stock: 1);
        $product = $this->offerOf($order)->product;

        $order->transitionTo(OrderStatus::Confirmed);
        $this->assertFalse($product->fresh()->in_stock);

        $order->fresh()->transitionTo(OrderStatus::Cancelled);

        $this->assertTrue($product->fresh()->in_stock);
    }
}
