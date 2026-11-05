<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Экспресс-покупка «в 1 клик»: телефон — и менеджер перезванивает.
 */
class QuickOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_покупка_в_1_клик_создаёт_заявку_без_имени(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.quick', $product), [
            'phone' => '+7 700 123-45-67',
            'privacy_consent' => '1',
        ])->assertRedirect(route('order.thanks'));

        $order = Order::sole();

        $this->assertSame(OrderSource::OneClick, $order->source);
        $this->assertSame('77001234567', $order->customer->phone);
        $this->assertStringContainsString('Экспресс-покупка', (string) $order->comment);
    }

    public function test_повтор_с_тем_же_токеном_не_плодит_дубли(): void
    {
        $product = Product::factory()->create();

        $payload = [
            'phone' => '+7 700 123-45-67',
            'privacy_consent' => '1',
            'form_token' => 'quick-token-1',
        ];

        $this->post(route('order.quick', $product), $payload);
        $this->post(route('order.quick', $product), $payload);

        $this->assertSame(1, Order::count());
    }

    public function test_ловушка_для_ботов_отклоняет_заявку(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.quick', $product), [
            'phone' => '+7 700 123-45-67',
            'privacy_consent' => '1',
            'website' => 'http://spam.example',
        ])->assertSessionHasErrors('website');

        $this->assertSame(0, Order::count());
    }

    public function test_без_телефона_заявка_не_создаётся(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.quick', $product), ['privacy_consent' => '1'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
    }
}
