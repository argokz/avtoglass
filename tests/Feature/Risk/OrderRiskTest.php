<?php

namespace Tests\Feature\Risk;

use App\Domain\Risk\RiskLevel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RiskBlocklistEntry;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Антифрод: velocity по телефону, стоп-лист и судьба заявки после вердикта.
 */
class OrderRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_первая_заявка_чистая(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->orderPayload());

        $order = Order::sole();
        $this->assertSame(RiskLevel::Allow, $order->risk_status);
        $this->assertNull($order->risk_reasons);
        // Обычная заявка получает задачу на перезвон.
        $this->assertTrue(Task::where('order_id', $order->id)->where('title', 'like', 'Перезвонить%')->exists());
    }

    public function test_частые_заявки_с_одного_телефона_уходят_на_проверку(): void
    {
        config()->set('risk.review_orders_per_hour', 2);
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->orderPayload());
        $this->post(route('order.store', $product), $this->orderPayload());

        $order = Order::query()->latest('id')->first();

        $this->assertSame(RiskLevel::Review, $order->risk_status);
        $this->assertContains('PHONE_VELOCITY_HOUR', $order->risk_reasons);
        $this->assertTrue(
            Task::where('order_id', $order->id)->where('title', 'like', 'Проверить%мошенничество')->exists()
        );
    }

    public function test_телефон_из_стоп_листа_блокируется_без_оповещения_смены(): void
    {
        RiskBlocklistEntry::create([
            'type' => 'phone',
            'value' => Customer::phoneHash('77001234567'),
            'reason' => 'тест',
        ]);

        $product = Product::factory()->create();
        $this->post(route('order.store', $product), $this->orderPayload());

        $order = Order::sole();

        $this->assertSame(RiskLevel::Block, $order->risk_status);
        $this->assertContains('PHONE_BLOCKLIST', $order->risk_reasons);
        // Мошеннику не перезванивают: ни задачи, ни оповещения.
        $this->assertFalse(Task::where('order_id', $order->id)->exists());
    }

    public function test_крупная_сумма_уходит_на_проверку(): void
    {
        config()->set('risk.review_amount', 1000);

        $product = Product::factory()->create(['price_min' => 50000, 'price_max' => 50000]);
        $this->post(route('order.store', $product), $this->orderPayload());

        $order = Order::sole();

        $this->assertSame(RiskLevel::Review, $order->risk_status);
        $this->assertContains('LARGE_AMOUNT', $order->risk_reasons);
    }
}
