<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Payments\Gateways\TestGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Оплата — место, где ошибка стоит дороже всего: либо клиент заплатил и не
 * получил заказ, либо заказ ушёл без оплаты. Поэтому здесь проверяется
 * не только счастливый путь.
 */
class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payableOrder(float $amount = 30000): Order
    {
        $order = Order::factory()->create(['total_amount' => $amount]);
        $order->items()->create(['name' => 'Лобовое стекло', 'price' => $amount, 'quantity' => 1]);

        return $order->fresh();
    }

    /** Подписываем так же, как это делает касса. */
    private function callbackUrl(Payment $payment, string $status, ?float $amount = null): string
    {
        $gateway = app(TestGateway::class);

        $data = [
            'token' => $payment->token,
            'status' => $status,
            'amount' => (string) ($amount ?? $payment->amount),
            'external_id' => 'test-'.$payment->id,
        ];

        return route('payment.callback', $data + ['signature' => $gateway->sign($data)]);
    }

    // ------------------------------------------------------------------
    // счастливый путь
    // ------------------------------------------------------------------

    public function test_успешная_оплата_подтверждает_заказ(): void
    {
        $order = $this->payableOrder();
        $payment = Payment::factory()->for($order)->create(['amount' => 30000]);

        $this->get($this->callbackUrl($payment, 'success'))
            ->assertRedirect(route('payment.result', $payment));

        $payment->refresh();
        $order->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertTrue($order->isPaid());
    }

    public function test_оплата_попадает_в_журнал_заказа(): void
    {
        $order = $this->payableOrder();
        $payment = Payment::factory()->for($order)->create(['amount' => 30000]);

        $this->get($this->callbackUrl($payment, 'success'));

        $this->assertTrue(
            $order->interactions()->where('body', 'like', '%Оплата получена%')->exists()
        );
    }

    public function test_страница_кассы_открывается(): void
    {
        $payment = Payment::factory()->create();

        $this->get(route('payment.mock', $payment))
            ->assertOk()
            ->assertSee('Тестовая касса');
    }

    // ------------------------------------------------------------------
    // защита
    // ------------------------------------------------------------------

    /** Без верной подписи уведомление — просто чужой запрос. */
    public function test_колбэк_без_подписи_отклоняется(): void
    {
        $payment = Payment::factory()->create(['amount' => 30000]);

        $this->get(route('payment.callback', [
            'token' => $payment->token,
            'status' => 'success',
            'amount' => '30000',
        ]))->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_подделанная_подпись_отклоняется(): void
    {
        $payment = Payment::factory()->create(['amount' => 30000]);

        $this->get(route('payment.callback', [
            'token' => $payment->token,
            'status' => 'success',
            'amount' => '30000',
            'signature' => str_repeat('a', 64),
        ]))->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    /**
     * Кассы шлют колбэк по нескольку раз, пока не получат ответ.
     * Второй заход не должен применяться повторно.
     */
    public function test_повторный_колбэк_не_применяется_дважды(): void
    {
        $order = $this->payableOrder();
        $payment = Payment::factory()->for($order)->create(['amount' => 30000]);

        $url = $this->callbackUrl($payment, 'success');

        $this->get($url);
        $firstPaidAt = $payment->fresh()->paid_at;

        Carbon::setTestNow(now()->addMinutes(5));
        $this->get($url);
        Carbon::setTestNow();

        $this->assertEquals($firstPaidAt, $payment->fresh()->paid_at);
        $this->assertSame(
            1,
            $order->interactions()->where('body', 'like', '%Оплата получена%')->count(),
            'запись об оплате должна быть одна'
        );
    }

    /** Сумма приходит извне, и доверять ей нельзя. */
    public function test_подмена_суммы_в_колбэке_не_проходит(): void
    {
        $order = $this->payableOrder(30000);
        $payment = Payment::factory()->for($order)->create(['amount' => 30000]);

        // подпись валидна, но сумма занижена
        $this->get($this->callbackUrl($payment, 'success', 100));

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('не совпала', $payment->failure_reason);
        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_неизвестный_платёж_даёт_404(): void
    {
        $gateway = app(TestGateway::class);

        $data = [
            'token' => (string) Str::uuid(),
            'status' => 'success',
            'amount' => '1000',
            'external_id' => 'x',
        ];

        $this->get(route('payment.callback', $data + ['signature' => $gateway->sign($data)]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // неудачные исходы
    // ------------------------------------------------------------------

    public function test_отказ_банка_не_подтверждает_заказ(): void
    {
        $order = $this->payableOrder();
        $payment = Payment::factory()->for($order)->create(['amount' => 30000]);

        $this->get($this->callbackUrl($payment, 'fail'));

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        $this->assertFalse($order->fresh()->isPaid());
    }

    public function test_отмена_клиентом_сохраняется_отдельно_от_отказа(): void
    {
        $payment = Payment::factory()->create(['amount' => 30000]);

        $this->get($this->callbackUrl($payment, 'cancel'));

        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // запуск оплаты
    // ------------------------------------------------------------------

    public function test_оплата_начинается_из_сессии_а_не_из_адреса(): void
    {
        $order = $this->payableOrder();

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertRedirect();

        $this->assertSame(1, $order->payments()->count());
    }

    public function test_без_заказа_в_сессии_оплату_не_начать(): void
    {
        $this->post(route('payment.start'))->assertNotFound();
    }

    /** Повторное нажатие «оплатить» не должно плодить платежи. */
    public function test_повторный_запуск_переиспользует_платёж(): void
    {
        $order = $this->payableOrder();

        $this->withSession(['last_order_number' => $order->number])->post(route('payment.start'));
        $this->withSession(['last_order_number' => $order->number])->post(route('payment.start'));

        $this->assertSame(1, $order->payments()->count());
    }

    public function test_оплаченный_заказ_повторно_не_оплатить(): void
    {
        $order = $this->payableOrder();
        Payment::factory()->for($order)->status(PaymentStatus::Succeeded)->create();

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertRedirect(route('order.thanks'))
            ->assertSessionHas('payment_error');
    }

    public function test_заявку_без_суммы_онлайн_не_оплатить(): void
    {
        $order = Order::factory()->create(['total_amount' => null]);

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertSessionHas('payment_error');

        $this->assertSame(0, $order->payments()->count());
    }

    public function test_отменённый_заказ_оплатить_нельзя(): void
    {
        $order = $this->payableOrder();
        $order->transitionTo(OrderStatus::Cancelled);

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertSessionHas('payment_error');
    }

    /** Резерв стекла под неоплаченную ссылку нельзя держать вечно. */
    public function test_протухшая_ссылка_не_принимается(): void
    {
        $payment = Payment::factory()->create();

        Carbon::setTestNow(now()->addMinutes((int) config('payments.ttl_minutes') + 1));

        $this->assertTrue($payment->isExpired());
        $this->assertFalse($payment->isPayable());

        $this->get(route('payment.mock', $payment))
            ->assertRedirect(route('payment.result', $payment));

        Carbon::setTestNow();
    }

    /** Кнопка оплаты появляется только когда платить есть за что. */
    public function test_кнопка_оплаты_видна_только_при_посчитанной_сумме(): void
    {
        $order = $this->payableOrder();

        $this->withSession(['last_order_number' => $order->number])
            ->get(route('order.thanks'))
            ->assertOk()
            ->assertSee('Оплатить картой');

        $empty = Order::factory()->create(['total_amount' => null]);

        $this->withSession(['last_order_number' => $empty->number])
            ->get(route('order.thanks'))
            ->assertOk()
            ->assertDontSee('Оплатить картой');
    }

    /** Заявка с сайта сразу получает сумму — иначе платить будет не за что. */
    public function test_заявка_с_карточки_товара_получает_сумму(): void
    {
        $product = Product::factory()->create(['price_min' => 27000]);
        Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 27000]);

        $this->post(route('order.store', $product), [
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ]);

        $this->assertSame('27000.00', Order::sole()->total_amount);
    }
}
