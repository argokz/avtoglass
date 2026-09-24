<?php

namespace Tests\Feature;

use App\Cart\Cart;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Оптовая заявка-спецификация (этап 1 плана PLAN.md).
 *
 * Оптовик шлёт список позиций с количеством — без корзины, без установки
 * и без оплаты при отправке: счёт, онлайн или при получении решает менеджер.
 */
class WholesaleOrderTest extends TestCase
{
    use RefreshDatabase;

    private function оптовик(int $скидка = 10): User
    {
        $customer = Customer::factory()->wholesale($скидка)->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
            'name' => 'Сервис',
        ]);

        return User::factory()->create([
            'role' => UserRole::Customer,
            'name' => 'Сервис',
            'phone' => $customer->phone,
            'password' => Hash::make('ochen-dlinnyi-parol'),
            'customer_id' => $customer->id,
        ]);
    }

    /** @return array{0:Product,1:Offer} */
    private function стекло(string $name, float $retail, float $cost): array
    {
        $product = Product::factory()->create([
            'name' => $name,
            'price_min' => $retail,
            'price_max' => $retail,
        ]);

        $offer = Offer::factory()->create([
            'product_id' => $product->id,
            'price_retail' => $retail,
            'price_wholesale' => $cost,
        ]);

        $product->refreshPricing();

        return [$product->fresh(), $offer->fresh()];
    }

    private function заявка(array $items, array $override = []): array
    {
        return array_merge([
            'items' => $items,
            'fulfillment' => FulfillmentType::Pickup->value,
            'privacy_consent' => '1',
        ], $override);
    }

    public function test_оптовик_отправляет_заявку_списком(): void
    {
        [$productA, $offerA] = $this->стекло('Лобовое стекло А', 30000, 20000);
        [$productB, $offerB] = $this->стекло('Боковое стекло Б', 50000, 30000);
        $user = $this->оптовик(10);

        $this->actingAs($user)
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offerA->id, 'qty' => 2],
                ['offer_id' => $offerB->id, 'qty' => 1],
            ]))
            ->assertRedirect(route('account.orders'));

        $order = Order::sole();

        $this->assertSame(OrderSource::Wholesale, $order->source);
        $this->assertNull($order->payment_method);
        $this->assertSame(FulfillmentType::Pickup, $order->fulfillment);
        $this->assertSame(2, $order->items()->count());
        // 10% от розницы: 27000×2 + 45000×1
        $this->assertSame('27000.00', $order->items()->where('product_id', $productA->id)->sole()->price);
        $this->assertSame('45000.00', $order->items()->where('product_id', $productB->id)->sole()->price);
        $this->assertSame('99000.00', $order->fresh()->total_amount);
        $this->assertStringContainsString('Организация', (string) $order->comment);

        $task = Task::sole();
        $this->assertStringContainsString('Оптовая заявка', $task->title);
        $this->assertSame($order->id, $task->order_id);
    }

    public function test_гостя_отправляем_на_вход(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        $this->post(route('wholesale.store'), $this->заявка([
            ['offer_id' => $offer->id, 'qty' => 1],
        ]))->assertRedirect(route('account.login'));

        $this->assertSame(0, Order::count());
    }

    public function test_розничному_клиенту_запрещено(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $customer = Customer::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]))
            ->assertForbidden();

        $this->assertSame(0, Order::count());
    }

    public function test_заявке_на_опт_на_проверке_запрещено(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $customer = Customer::factory()->pendingWholesale()->create();
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]))
            ->assertForbidden();
    }

    public function test_без_позиций_заявка_не_уходит(): void
    {
        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка([]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_количество_ограничено_сверху(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => Cart::MAX_WHOLESALE_QUANTITY + 1],
            ]))
            ->assertSessionHasErrors('items.0.qty');
    }

    public function test_цена_позиции_не_ниже_закупа(): void
    {
        [$product, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        $this->actingAs($this->оптовик(40))
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]));

        $item = Order::sole()->items()->sole();

        // 40% от 30 000 — это 18 000, но ниже закупа 20 000 не опускаемся.
        $this->assertSame('20000.00', $item->price);
    }

    public function test_установки_у_опта_нет(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка(
                [['offer_id' => $offer->id, 'qty' => 1]],
                ['fulfillment' => FulfillmentType::Installation->value],
            ))
            ->assertSessionHasErrors('fulfillment');
    }

    public function test_доставка_требует_адрес(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка(
                [['offer_id' => $offer->id, 'qty' => 1]],
                ['fulfillment' => FulfillmentType::Delivery->value],
            ))
            ->assertSessionHasErrors('address');
    }

    public function test_порог_суммы_настраивается_в_crm(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $user = $this->оптовик(10);

        settings()->save(['wholesale_min_total' => 100000]);

        $this->actingAs($user)
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::count());

        settings()->save(['wholesale_min_total' => 0]);

        $this->actingAs($user)
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]))
            ->assertRedirect(route('account.orders'));

        $this->assertSame(1, Order::count());
    }

    public function test_порог_количества_из_настроек(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        settings()->save(['wholesale_min_qty' => 5]);

        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 2],
            ]))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_повтор_с_тем_же_токеном_не_дублирует_заявку(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $user = $this->оптовик();
        $payload = $this->заявка(
            [['offer_id' => $offer->id, 'qty' => 1]],
            ['form_token' => 'opt-token-1'],
        );

        $this->actingAs($user)->post(route('wholesale.store'), $payload);
        $this->actingAs($user)->post(route('wholesale.store'), $payload);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Task::count());
    }

    public function test_позиция_снятая_с_витрины_не_проходит(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $offer->update(['is_active' => false]);

        $this->actingAs($this->оптовик())
            ->post(route('wholesale.store'), $this->заявка([
                ['offer_id' => $offer->id, 'qty' => 1],
            ]))
            ->assertSessionHasErrors('items.0.offer_id');

        $this->assertSame(0, Order::count());
    }
}
