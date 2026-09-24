<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\WholesaleStatus;
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
 * Опт: цены только после одобрения менеджера, закуп на витрину не попадает.
 */
class WholesaleTest extends TestCase
{
    use RefreshDatabase;

    private function стекло(): Product
    {
        $product = Product::factory()->create([
            'name' => 'Лобовое стекло опт',
            'price_min' => 30000,
            'price_max' => 30000,
            'eurocode' => '7260AGNGYV',
        ]);

        Offer::factory()->create([
            'product_id' => $product->id,
            'price_retail' => 30000,
            'price_wholesale' => 20000,
        ]);

        $product->refreshPricing();

        return $product->fresh();
    }

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

    public function test_гость_видит_розницу_и_не_видит_закуп(): void
    {
        $product = $this->стекло();

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('30 000')
            ->assertDontSee('20 000')
            ->assertDontSee('Оптовая цена');
    }

    public function test_заявка_на_опт_не_меняет_цену(): void
    {
        $product = $this->стекло();
        $customer = Customer::factory()->pendingWholesale()->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
        ]);
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'phone' => $customer->phone,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('30 000')
            ->assertDontSee('Оптовая цена');
    }

    public function test_оптовый_клиент_видит_скидку_не_ниже_закупа(): void
    {
        $product = $this->стекло();

        $this->actingAs($this->оптовик(10))
            ->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('Оптовая цена')
            ->assertSee('27 000')
            ->assertDontSee('20 000');
    }

    public function test_скидка_не_опускает_цену_ниже_закупа(): void
    {
        $product = $this->стекло();

        $this->actingAs($this->оптовик(40))
            ->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('20 000')
            ->assertDontSee('18 000');
    }

    public function test_в_заказ_попадает_оптовая_цена(): void
    {
        $product = $this->стекло();
        $user = $this->оптовик(10);

        $this->actingAs($user)
            ->post(route('order.store', $product), $this->orderPayload([
                'name' => $user->name,
                'phone' => '+7 700 123-45-67',
            ]))
            ->assertRedirect(route('order.thanks'));

        $item = Order::sole()->items()->whereNotNull('product_id')->first();

        $this->assertSame('27000.00', $item->price);
        $this->assertStringContainsString('опт', (string) Order::sole()->comment);
    }

    public function test_регистрация_опта_ставит_заявку_менеджеру(): void
    {
        $this->post(route('account.register.store'), [
            'name' => 'Сервис',
            'phone' => '+7 700 123-45-67',
            'password' => 'ochen-dlinnyi-parol',
            'password_confirmation' => 'ochen-dlinnyi-parol',
            'wholesale' => '1',
            'company_name' => 'ТОО Стекло',
            'bin' => '123456789012',
        ])->assertRedirect(route('account.orders'));

        $customer = Customer::sole();

        $this->assertSame(WholesaleStatus::Pending, $customer->wholesale_status);
        $this->assertSame('ТОО Стекло', $customer->company_name);
        $this->assertSame(1, Task::where('customer_id', $customer->id)->count());
        $this->assertFalse($customer->seesWholesalePrices());
    }

    public function test_профиль_сам_опт_не_включает(): void
    {
        $user = $this->оптовик(10);
        $user->customer->update([
            'wholesale_status' => WholesaleStatus::Retail,
            'wholesale_discount_percent' => null,
            'wholesale_approved_at' => null,
        ]);

        $this->actingAs($user->fresh())
            ->post(route('account.profile.update'), [
                'name' => $user->name,
                'wholesale_status' => 'wholesale',
                'wholesale_discount_percent' => 40,
                'company_name' => 'Хакеры',
            ])
            ->assertRedirect(route('account.profile'));

        $this->assertSame(WholesaleStatus::Retail, $user->customer->fresh()->wholesale_status);
        $this->assertNull($user->customer->fresh()->wholesale_discount_percent);
    }

    public function test_в_корзине_оптовая_цена_и_больше_штук(): void
    {
        $product = $this->стекло();
        $user = $this->оптовик(10);

        $this->actingAs($user)
            ->post(route('cart.add', $product), [
                'quantity' => 21,
                'go_to_cart' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('cart.show'))
            ->assertOk()
            ->assertSee('Оптовые цены')
            ->assertSee('27 000')
            ->assertDontSee('20 000');

        $this->assertSame(21, app(\App\Cart\Cart::class)->count());
    }

    public function test_в_поиске_опт_не_показывает_закуп(): void
    {
        $this->стекло();

        $this->actingAs($this->оптовик(10))
            ->get(route('search', ['q' => 'Лобовое стекло опт']))
            ->assertOk()
            ->assertSee('27 000')
            ->assertSee('В корзину')
            ->assertDontSee('20 000');
    }
}
