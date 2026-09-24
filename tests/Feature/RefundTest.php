<?php

namespace Tests\Feature;

use App\Actions\RefundPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Enums\ReservationStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_успешный_платёж_можно_вернуть(): void
    {
        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
        ]);

        $this->assertTrue(app(RefundPayment::class)->handle($payment));
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(27000.0, $order->fresh()->outstanding());
    }

    public function test_возврат_выбивает_чек_в_офд(): void
    {
        settings()->save(['fiscal' => true]);
        config([
            'fiscal.webkassa.login' => 'demo@webkassa.kz',
            'fiscal.webkassa.password' => 'secret',
            'fiscal.webkassa.cashbox' => 'SWK00000001',
            'fiscal.webkassa.test_mode' => true,
        ]);

        Http::fake([
            '*/api/Authorize' => Http::response(['Data' => ['Token' => 'WK-TOKEN']]),
            '*/api/Check' => Http::response(['Data' => [
                'CheckNumber' => 'R-001',
                'TicketUrl' => 'https://devkkm.webkassa.kz/t/R-001',
                'DateTime' => '2026-08-27 12:00:00',
            ]]),
        ]);

        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
        ]);
        Receipt::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount' => 27000,
            'status' => ReceiptStatus::Issued,
            'check_number' => '000123',
        ]);

        app(RefundPayment::class)->handle($payment);

        $this->assertSame(2, Receipt::count());
        $this->assertSame('R-001', Receipt::orderByDesc('id')->first()->check_number);

        Http::assertSent(fn (Request $request) => ($request['OperationType'] ?? null) === 3);
    }

    /** Полный возврат возвращает стекло на витрину — иначе бронь висит бессрочно. */
    public function test_полный_возврат_снимает_резерв(): void
    {
        $offer = Offer::factory()->price(27000)->create(['stock_qty' => 2]);
        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'total_amount' => 27000,
        ]);
        $order->items()->create([
            'product_id' => $offer->product_id,
            'offer_id' => $offer->id,
            'name' => 'Лобовое Camry',
            'price' => 27000,
            'quantity' => 1,
        ]);
        $order->transitionTo(OrderStatus::Confirmed);

        $payment = Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
        ]);

        $this->assertSame(1, $offer->fresh()->available());

        app(RefundPayment::class)->handle($payment);

        $this->assertSame(0, $order->reservations()->where('status', ReservationStatus::Held)->count());
        $this->assertSame(2, $offer->fresh()->available());
    }

    /** Частичный возврат бронь не снимает: по заказу ещё есть живой платёж. */
    public function test_частичный_возврат_оставляет_резерв(): void
    {
        $offer = Offer::factory()->price(27000)->create(['stock_qty' => 1]);
        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'total_amount' => 27000,
        ]);
        $order->items()->create([
            'product_id' => $offer->product_id,
            'offer_id' => $offer->id,
            'name' => 'Лобовое Camry',
            'price' => 27000,
            'quantity' => 1,
        ]);
        $order->transitionTo(OrderStatus::Confirmed);

        $first = Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 10000,
            'paid_at' => now(),
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Succeeded,
            'amount' => 17000,
            'paid_at' => now(),
        ]);

        app(RefundPayment::class)->handle($first);

        $this->assertSame(1, $order->reservations()->where('status', ReservationStatus::Held)->count());
        $this->assertSame(0, $offer->fresh()->available());
    }

    /** Отказ кассы не должен оставлять в заявке «возвращён» при живых деньгах. */
    public function test_отказ_банка_не_меняет_статус_платежа(): void
    {
        Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/operation/*' => Http::response('denied', 400),
        ]);

        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'halyk',
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
            'external_id' => 'op-1',
        ]);

        $this->assertFalse(app(RefundPayment::class)->handle($payment));
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_наличные_возвращают_без_кассы_банка(): void
    {
        Http::fake();

        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'cash',
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
        ]);

        $this->assertTrue(app(RefundPayment::class)->handle($payment));
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_неизвестный_драйвер_не_помечает_возврат(): void
    {
        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'unknown-bank',
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
        ]);

        $this->assertFalse(app(RefundPayment::class)->handle($payment));
        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
    }

    public function test_возврат_halyk_идёт_в_банк_до_смены_статуса(): void
    {
        Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/operation/*/refund' => Http::response(['status' => 'ok']),
        ]);

        $order = Order::factory()->create(['total_amount' => 27000]);
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'halyk',
            'status' => PaymentStatus::Succeeded,
            'amount' => 27000,
            'paid_at' => now(),
            'external_id' => 'op-77',
        ]);

        $this->assertTrue(app(RefundPayment::class)->handle($payment));
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/operation/op-77/refund'));
    }
}
