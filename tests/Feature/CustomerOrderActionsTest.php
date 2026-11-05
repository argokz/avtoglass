<?php

namespace Tests\Feature;

use App\Ai\AssistantTools;
use App\Cart\Cart;
use App\Domain\Identity\BuyerContext;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Что покупатель делает с готовым заказом сам: отмена, «заказать снова»
 * и те же действия текстом в чате — где ИИ только показывает кнопку.
 */
class CustomerOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    private function покупатель(): User
    {
        $customer = Customer::factory()->create();

        return User::factory()->create(['role' => UserRole::Customer, 'customer_id' => $customer->id, 'phone' => $customer->phone]);
    }

    private function заказ(User $user, OrderStatus $status = OrderStatus::New): Order
    {
        $product = Product::factory()->create(['is_published' => true]);
        $offer = Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 30000]);
        $order = Order::factory()->for($user->customer)->status($status)->create(['total_amount' => 30000]);
        $order->items()->create(['product_id' => $product->id, 'offer_id' => $offer->id, 'name' => $product->name, 'price' => 30000, 'quantity' => 1]);

        return $order;
    }

    public function test_новый_заказ_покупатель_отменяет_сам_и_менеджер_узнаёт(): void
    {
        $user = $this->покупатель();
        $order = $this->заказ($user);

        $this->actingAs($user)
            ->post(route('account.order.cancel', $order->public_token), ['reason' => 'Нашёл дешевле'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($text) => str_contains($text, 'отменён'));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $task = Task::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($task);
        $this->assertStringContainsString('Нашёл дешевле', $task->description);
    }

    public function test_согласованный_заказ_сам_не_отменяется(): void
    {
        $user = $this->покупатель();
        $order = $this->заказ($user, OrderStatus::Confirmed);

        $this->actingAs($user)
            ->post(route('account.order.cancel', $order->public_token))
            ->assertSessionHas('status', fn ($text) => str_contains($text, 'менеджер'));

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_оплаченный_заказ_сам_не_отменяется(): void
    {
        $user = $this->покупатель();
        $order = $this->заказ($user);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Succeeded, 'amount' => 30000]);

        $this->actingAs($user)->post(route('account.order.cancel', $order->public_token));

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_чужой_заказ_не_отменить(): void
    {
        $чужой = $this->заказ($this->покупатель());

        $this->actingAs($this->покупатель())
            ->post(route('account.order.cancel', $чужой->public_token))
            ->assertNotFound();

        $this->assertSame(OrderStatus::New, $чужой->fresh()->status);
    }

    public function test_заказать_снова_кладёт_позиции_в_корзину(): void
    {
        $user = $this->покупатель();
        $order = $this->заказ($user, OrderStatus::Completed);

        $this->actingAs($user)
            ->post(route('account.order.reorder', $order->public_token))
            ->assertRedirect(route('cart.show'));

        $this->assertSame(1, app(Cart::class)->count());
    }

    public function test_кабинет_показывает_кнопки_по_статусу(): void
    {
        $user = $this->покупатель();
        $this->заказ($user);

        $this->actingAs($user)->get(route('account.orders'))
            ->assertOk()
            ->assertSee('Заказать снова')
            ->assertSee('Отменить');
    }

    public function test_ии_отмену_только_показывает_а_не_выполняет(): void
    {
        $user = $this->покупатель();
        $order = $this->заказ($user);
        $buyer = BuyerContext::account($user->id, 'ru');

        $result = app(AssistantTools::class)->call('cancel_order', ['order_number' => (string) $order->id], $buyer);

        $this->assertSame($order->number, $result['order']['number']);
        $this->assertTrue($result['cancel']['allowed']);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_ии_не_находит_чужой_заказ_по_номеру(): void
    {
        $чужой = $this->заказ($this->покупатель());
        $buyer = BuyerContext::account($this->покупатель()->id, 'ru');

        $result = app(AssistantTools::class)->call('pay_order', ['order_number' => $чужой->number], $buyer);

        $this->assertSame('RESOURCE_NOT_FOUND', $result['code']);
    }
}
