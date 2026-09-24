<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\WholesaleTable;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Оптовая витрина /opt (этап 2 плана PLAN.md): таблица с галочками,
 * поиск по еврокоду, итог. Корзины у оптовика нет.
 */
class WholesaleTableTest extends TestCase
{
    use RefreshDatabase;

    private function оптовик(int $скидка = 10): User
    {
        $customer = Customer::factory()->wholesale($скидка)->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
        ]);

        return User::factory()->create([
            'role' => UserRole::Customer,
            'phone' => $customer->phone,
            'password' => Hash::make('ochen-dlinnyi-parol'),
            'customer_id' => $customer->id,
        ]);
    }

    /** @return array{0:Product,1:Offer} */
    private function стекло(string $name, float $retail, float $cost, array $extra = [], array $offerExtra = []): array
    {
        $product = Product::factory()->create(array_merge([
            'name' => $name,
            'price_min' => $retail,
            'price_max' => $retail,
        ], $extra));

        $offer = Offer::factory()->create(array_merge([
            'product_id' => $product->id,
            'price_retail' => $retail,
            'price_wholesale' => $cost,
        ], $offerExtra));

        $product->refreshPricing();

        return [$product->fresh(), $offer->fresh()];
    }

    public function test_оптовик_видит_таблицу_с_оптовой_ценой_без_закупа(): void
    {
        $this->стекло('Лобовое стекло опт', 30000, 20000, ['eurocode' => '7260AGNGYV']);

        $this->actingAs($this->оптовик())
            ->get(route('wholesale.index'))
            ->assertOk()
            ->assertSee('Оптовая заявка')
            ->assertSee('7260AGNGYV')
            ->assertSee('27 000')
            ->assertDontSee('20 000');
    }

    public function test_розничный_видит_приглашение_подать_заявку(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->get(route('wholesale.index'))
            ->assertOk()
            ->assertSee('Подать заявку на опт')
            ->assertDontSee('Оптовая заявка</h1>', false);
    }

    public function test_заявка_на_проверке_видит_свой_экран(): void
    {
        $customer = Customer::factory()->pendingWholesale()->create();
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->get(route('wholesale.index'))
            ->assertOk()
            ->assertSee('Заявка на опт на проверке');
    }

    public function test_гость_уходит_на_вход(): void
    {
        $this->get(route('wholesale.index'))
            ->assertRedirect(route('account.login'));
    }

    public function test_галочка_добавляет_позицию_в_итог(): void
    {
        [, $offerA] = $this->стекло('Лобовое стекло А', 30000, 20000);
        [, $offerB] = $this->стекло('Боковое стекло Б', 50000, 30000);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->assertSee('Лобовое стекло А')
            ->call('toggle', $offerA->id)
            ->call('toggle', $offerB->id)
            ->call('setQty', $offerA->id, 2)
            ->assertSet('qty.'.$offerA->id, 2)
            // 27000×2 + 45000×1 = 99 000
            ->assertSee('99 000')
            ->call('remove', $offerB->id)
            ->assertDontSee('99 000');
    }

    public function test_количество_зажато_пределом(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->call('toggle', $offer->id)
            ->call('setQty', $offer->id, 500)
            ->assertSet('qty.'.$offer->id, \App\Cart\Cart::MAX_WHOLESALE_QUANTITY)
            ->call('setQty', $offer->id, 0)
            ->assertSet('qty.'.$offer->id, 1);
    }

    public function test_поиск_по_еврокоду_сужает_список(): void
    {
        $this->стекло('Лобовое стекло А', 30000, 20000, ['eurocode' => '7260AGNGYV']);
        $this->стекло('Боковое стекло Б', 50000, 30000, ['eurocode' => '43R001122']);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->assertSee('Лобовое стекло А')
            ->assertSee('Боковое стекло Б')
            ->set('q', '7260')
            ->assertSee('Лобовое стекло А')
            ->assertDontSee('Боковое стекло Б');
    }

    public function test_фильтр_наличия(): void
    {
        // in_stock считает refreshPricing из остатка оффера, поэтому задаём stock_qty.
        $this->стекло('Лобовое стекло А', 30000, 20000, [], ['stock_qty' => 5]);
        $this->стекло('Боковое стекло Б', 50000, 30000);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->set('inStock', true)
            ->assertSee('Лобовое стекло А')
            ->assertDontSee('Боковое стекло Б');
    }

    public function test_фильтр_по_марке_стекла(): void
    {
        [$productA, $offerA] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $this->стекло('Боковое стекло Б', 50000, 30000);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->set('supplierId', $offerA->supplier_id)
            ->assertSee('Лобовое стекло А')
            ->assertDontSee('Боковое стекло Б');
    }

    public function test_форма_содержит_скрытые_поля_выбранного(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->call('toggle', $offer->id)
            ->assertSee('name="items[0][offer_id]"', false)
            ->assertSee('value="'.$offer->id.'"', false)
            ->assertSee(route('wholesale.store'), false);
    }

    public function test_кабинет_оптовика_ведёт_на_витрину(): void
    {
        $this->actingAs($this->оптовик())
            ->get(route('account.orders'))
            ->assertOk()
            ->assertSee('Новая оптовая заявка')
            ->assertSee('href="'.route('wholesale.index').'"', false);
    }

    public function test_розничный_не_видит_оптовую_кнопку_в_кабинете(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create([
            'role' => UserRole::Customer,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)
            ->get(route('account.orders'))
            ->assertOk()
            ->assertDontSee('Новая оптовая заявка');
    }

    public function test_шапка_оптовика_ведёт_на_витрину(): void
    {
        $this->actingAs($this->оптовик())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('wholesale.index').'"', false);
    }

    public function test_повтор_заявки_предзаполняет_выбор(): void
    {
        [, $offerA] = $this->стекло('Лобовое стекло А', 30000, 20000);
        [, $offerB] = $this->стекло('Боковое стекло Б', 50000, 30000);
        $user = $this->оптовик();

        $order = app(\App\Actions\CreateOrder::class)->fromWholesaleSelection(
            $user->customer,
            [
                ['offer_id' => $offerA->id, 'qty' => 3],
                ['offer_id' => $offerB->id, 'qty' => 1],
            ],
            ['fulfillment' => 'pickup', 'privacy_consent' => '1'],
        );

        $this->actingAs($user)
            ->get(route('wholesale.index', ['repeat' => $order->id]))
            ->assertOk()
            ->assertSee('name="items[0][offer_id]"', false)
            ->assertSee('value="'.$offerA->id.'"', false)
            ->assertSee('value="'.$offerB->id.'"', false)
            ->assertSee('value="3"', false);
    }

    public function test_чужая_заявка_не_повторяется(): void
    {
        [, $offer] = $this->стекло('Лобовое стекло А', 30000, 20000);
        $user = $this->оптовик();

        $чужой = Customer::factory()->wholesale()->create();
        $order = app(\App\Actions\CreateOrder::class)->fromWholesaleSelection(
            $чужой,
            [['offer_id' => $offer->id, 'qty' => 2]],
            ['fulfillment' => 'pickup', 'privacy_consent' => '1'],
        );

        $this->actingAs($user)
            ->get(route('wholesale.index', ['repeat' => $order->id]))
            ->assertOk()
            ->assertDontSee('name="items[0][offer_id]"', false);
    }
}
