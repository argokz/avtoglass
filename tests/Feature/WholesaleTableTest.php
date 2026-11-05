<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Cart\Cart;
use App\Enums\UserRole;
use App\Livewire\WholesaleTable;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Models\WholesaleList;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            ->assertSet('qty.'.$offer->id, Cart::MAX_WHOLESALE_QUANTITY)
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

        $order = app(CreateOrder::class)->fromWholesaleSelection(
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
        $order = app(CreateOrder::class)->fromWholesaleSelection(
            $чужой,
            [['offer_id' => $offer->id, 'qty' => 2]],
            ['fulfillment' => 'pickup', 'privacy_consent' => '1'],
        );

        $this->actingAs($user)
            ->get(route('wholesale.index', ['repeat' => $order->id]))
            ->assertOk()
            ->assertDontSee('name="items[0][offer_id]"', false);
    }

    public function test_вставленный_список_отмечает_позиции_по_кодам(): void
    {
        [, $лобовое] = $this->стекло('Лобовое опт', 30000, 20000, ['eurocode' => '2001AGNMVZ']);
        [, $заднее] = $this->стекло('Заднее опт', 15000, 9000, ['eurocode' => '2001BGNH']);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->set('pasted', '2001AGNMVZ 3
2001-bgnh;2
NETTAKOGO 1')
            ->call('addPasted')
            ->assertSet("qty.{$лобовое->id}", 3)
            ->assertSet("qty.{$заднее->id}", 2)
            ->assertSet('notFound', ['NETTAKOGO']);
    }

    public function test_спорный_код_ждёт_выбора_марки(): void
    {
        [$product, $agc] = $this->стекло('Лобовое опт', 30000, 20000, ['eurocode' => '2001AGNMVZ']);
        $fuyao = Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 28000, 'price_wholesale' => 18000]);

        $component = Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->set('pasted', '2001AGNMVZ 4')
            ->call('addPasted')
            ->assertSet('checked', []);

        $this->assertCount(2, $component->get('ambiguous')[0]['offers']);

        $component->call('chooseAmbiguous', 0, $fuyao->id)
            ->assertSet("qty.{$fuyao->id}", 4)
            ->assertSet('ambiguous', []);
    }

    public function test_сохранённый_список_загружается_в_один_клик(): void
    {
        [, $offer] = $this->стекло('Лобовое опт', 30000, 20000);
        $user = $this->оптовик();

        Livewire::actingAs($user)
            ->test(WholesaleTable::class)
            ->call('toggle', $offer->id)
            ->call('setQty', $offer->id, 5)
            ->set('listName', 'Еженедельная')
            ->call('saveList')
            ->call('clearSelection')
            ->assertSet('checked', []);

        $list = WholesaleList::query()->where('customer_id', $user->customer_id)->firstOrFail();

        Livewire::actingAs($user)
            ->test(WholesaleTable::class)
            ->call('loadList', $list->id)
            ->assertSet("qty.{$offer->id}", 5);
    }

    public function test_чужой_список_не_загружается(): void
    {
        [, $offer] = $this->стекло('Лобовое опт', 30000, 20000);
        $чужой = WholesaleList::query()->create([
            'customer_id' => Customer::factory()->wholesale()->create()->id,
            'name' => 'Чужой', 'items' => [['offer_id' => $offer->id, 'qty' => 3]],
        ]);

        Livewire::actingAs($this->оптовик())
            ->test(WholesaleTable::class)
            ->call('loadList', $чужой->id)
            ->assertSet('checked', []);
    }

    public function test_день_регулярной_заявки_ставится_только_у_своего_списка(): void
    {
        [, $offer] = $this->стекло('Лобовое опт', 30000, 20000);
        $user = $this->оптовик();
        $свой = WholesaleList::query()->create(['customer_id' => $user->customer_id, 'name' => 'Неделя', 'items' => [['offer_id' => $offer->id, 'qty' => 2]]]);
        $чужой = WholesaleList::query()->create(['customer_id' => Customer::factory()->wholesale()->create()->id, 'name' => 'Чужой', 'items' => []]);

        Livewire::actingAs($user)
            ->test(WholesaleTable::class)
            ->call('setReminder', $свой->id, '1')
            ->call('setReminder', $чужой->id, '1')
            ->call('setReminder', $свой->id, '9');

        $this->assertSame(1, $свой->fresh()->remind_weekday);
        $this->assertNull($чужой->fresh()->remind_weekday);
    }

    public function test_ссылка_из_напоминания_отмечает_список_и_он_первый_в_свой_день(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00')); // понедельник
        [, $offer] = $this->стекло('Лобовое опт', 30000, 20000);
        $user = $this->оптовик();
        $list = WholesaleList::query()->create(['customer_id' => $user->customer_id, 'name' => 'Неделя', 'items' => [['offer_id' => $offer->id, 'qty' => 4]], 'remind_weekday' => 1]);

        Livewire::actingAs($user)
            ->withQueryParams(['list' => $list->id])
            ->test(WholesaleTable::class)
            ->assertSet("qty.{$offer->id}", 4)
            ->assertSee('Сегодня по графику');
    }

    public function test_напоминание_уходит_оптовику_один_раз_в_день(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:05'));
        config(['notifications.whatsapp.green_instance' => '1101', 'notifications.whatsapp.green_token' => 'test-token']);
        Http::fake(['api.green-api.com/*' => Http::response(['idMessage' => 'x'])]);
        [, $offer] = $this->стекло('Лобовое опт', 30000, 20000);
        $user = $this->оптовик();
        WholesaleList::query()->create(['customer_id' => $user->customer_id, 'name' => 'Неделя', 'items' => [['offer_id' => $offer->id, 'qty' => 4]], 'remind_weekday' => 1]);
        WholesaleList::query()->create(['customer_id' => $user->customer_id, 'name' => 'Вторник', 'items' => [], 'remind_weekday' => 2]);
        WholesaleList::query()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Розница', 'items' => [], 'remind_weekday' => 1]);

        $this->artisan('wholesale:remind')->assertSuccessful();
        $this->artisan('wholesale:remind')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chatId'] === '77001234567@c.us'
            && str_contains($request['message'], '«Неделя»')
            && ! str_contains($request['message'], 'Вторник'));
    }

    public function test_без_whatsapp_напоминание_никуда_не_ходит(): void
    {
        config(['notifications.whatsapp.green_instance' => null]);
        Http::fake();

        $this->artisan('wholesale:remind')->assertSuccessful();

        Http::assertNothingSent();
    }
}
