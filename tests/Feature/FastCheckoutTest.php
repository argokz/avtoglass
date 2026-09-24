<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Розница — быстрее (этап 4 плана PLAN.md).
 *
 * Вошедшему клиенту контакты в форме свёрнуты в «Имя · телефон — Изменить»:
 * заявка уходит в одно нажатие. На карточке товара у телефона есть липкая
 * нижняя панель с ценой и кнопкой.
 */
class FastCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function клиент(): User
    {
        $customer = Customer::factory()->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
            'name' => 'Айгерим',
        ]);

        return User::factory()->create([
            'role' => UserRole::Customer,
            'name' => 'Айгерим',
            'phone' => $customer->phone,
            'password' => Hash::make('ochen-dlinnyi-parol'),
            'customer_id' => $customer->id,
        ]);
    }

    private function стекло(): Product
    {
        $product = Product::factory()->create([
            'name' => 'Лобовое стекло быстрое',
            'price_min' => 30000,
            'price_max' => 30000,
        ]);

        Offer::factory()->create([
            'product_id' => $product->id,
            'price_retail' => 30000,
            'price_wholesale' => 20000,
        ]);

        $product->refreshPricing();

        return $product->fresh();
    }

    public function test_вошедший_видит_контакты_свёрнутыми(): void
    {
        $product = $this->стекло();
        $user = $this->клиент();

        $this->actingAs($user)->post(route('cart.add', $product));

        $this->actingAs($user)
            ->get(route('cart.show'))
            ->assertOk()
            ->assertSee('data-contacts-collapsed', false)
            ->assertSee('Айгерим');
    }

    public function test_гость_видит_поля_контактов_развёрнутыми(): void
    {
        $product = $this->стекло();

        $this->post(route('cart.add', $product));

        $this->get(route('cart.show'))
            ->assertOk()
            ->assertDontSee('data-contacts-collapsed', false);
    }

    public function test_карточка_товара_с_липкой_панелью_на_телефоне(): void
    {
        $product = $this->стекло();

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('has-mobile-cta', false)
            ->assertSee('Оформить');
    }

    public function test_заявка_вошедшего_привязана_к_кабинету(): void
    {
        $product = $this->стекло();
        $user = $this->клиент();

        $this->actingAs($user)
            ->post(route('order.store', $product), [
                'name' => 'Айгерим',
                'phone' => '+7 700 123-45-67',
                'privacy_consent' => '1',
            ])
            ->assertRedirect();

        $order = Order::sole();
        $this->assertSame($user->customer_id, $order->customer_id);
    }
}
