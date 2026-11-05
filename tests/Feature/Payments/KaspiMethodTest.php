<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kaspi Pay как способ оплаты на витрине: выбор уходит в драйвер kaspi.
 */
class KaspiMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_kaspi_это_онлайн_способ_со_своим_драйвером(): void
    {
        $this->assertSame('kaspi', PaymentMethod::Kaspi->driver());
        $this->assertNull(PaymentMethod::Cash->driver());
        $this->assertSame('halyk', PaymentMethod::Card->driver());
    }

    public function test_заявка_с_kaspi_уходит_в_кассу_kaspi(): void
    {
        config()->set('payments.kaspi.test_mode', true);
        settings()->save(['payments' => true]);

        $product = Product::factory()->create(['price_min' => 30000, 'price_max' => 30000]);

        $response = $this->post(route('order.store', $product), $this->orderPayload([
            'payment_method' => PaymentMethod::Kaspi->value,
        ]));

        $order = Order::sole();
        $this->assertSame(PaymentMethod::Kaspi, $order->payment_method);
        $this->assertTrue($order->wantsOnlinePayment());

        $payment = Payment::sole();
        $this->assertSame('kaspi', $payment->provider);

        // Тестовый режим ведёт на встроенную эмуляцию Kaspi QR.
        $response->assertRedirect(route('payment.mock', [
            'payment' => $payment,
            'gateway' => 'kaspi',
            'title' => 'Оплата через Kaspi QR',
        ]));
    }
}
