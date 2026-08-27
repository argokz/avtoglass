<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $override = []): array
    {
        return array_merge([
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ], $override);
    }

    public function test_заявка_из_карточки_создаёт_заказ_и_клиента(): void
    {
        $product = Product::factory()->create(['name' => 'Лобовое стекло Camry']);

        $this->post(route('order.store', $product), $this->valid(['comment' => 'Трещина справа']))
            ->assertRedirect(route('order.thanks'));

        $order = Order::sole();

        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(OrderSource::ProductPage, $order->source);
        $this->assertStringContainsString('Трещина справа', $order->comment);
        $this->assertSame('77001234567', $order->customer->phone);
        $this->assertSame('Данил', $order->customer->name);
    }

    /** Позиция должна остаться в заказе, даже если карточка потом изменится. */
    public function test_позиция_сохраняется_снимком(): void
    {
        $product = Product::factory()->create(['name' => 'Лобовое Camry', 'price_min' => 30000]);
        $supplier = Supplier::factory()->create(['name' => 'КМК']);
        Offer::factory()->create([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'price_retail' => 27000,
        ]);

        $this->post(route('order.store', $product), $this->valid());

        $item = Order::sole()->items()->sole();

        $this->assertSame('Лобовое Camry', $item->name);
        $this->assertSame('КМК', $item->supplier_name);
        $this->assertSame('27000.00', $item->price);

        // карточку переименовали и сняли с витрины — заказ не должен измениться
        $product->update(['name' => 'Другое имя', 'is_published' => false]);

        $this->assertSame('Лобовое Camry', $item->fresh()->name);
    }

    /** Из нескольких предложений в заявку идёт самое дешёвое. */
    public function test_в_заявку_попадает_самое_дешёвое_предложение(): void
    {
        $product = Product::factory()->create();
        Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 40000]);
        Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 25000]);

        $this->post(route('order.store', $product), $this->valid());

        $this->assertSame('25000.00', Order::sole()->items()->sole()->price);
    }

    public function test_заявка_на_подбор_создаётся_без_товара(): void
    {
        $this->post(route('order.selection'), $this->valid(['car_info' => 'Toyota Camry 2018']))
            ->assertRedirect(route('order.thanks'));

        $order = Order::sole();

        $this->assertSame(OrderSource::Selection, $order->source);
        $this->assertCount(0, $order->items);
        $this->assertStringContainsString('Toyota Camry 2018', $order->comment);
    }

    /** Один и тот же человек не должен раздваиваться из-за формата номера. */
    public function test_разные_записи_телефона_ведут_к_одному_клиенту(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['phone' => '+7 700 123-45-67']));
        $this->post(route('order.store', $product), $this->valid(['phone' => '87001234567']));

        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Customer::sole()->orders_count);
    }

    /** Менеджер мог поправить имя руками — форма с сайта не должна его затирать. */
    public function test_форма_не_затирает_исправленное_имя_клиента(): void
    {
        $product = Product::factory()->create();
        Customer::create(['phone' => '77001234567', 'name' => 'Данил Шафеев']);

        $this->post(route('order.store', $product), $this->valid(['name' => 'данил']));

        $this->assertSame('Данил Шафеев', Customer::sole()->name);
    }

    public function test_без_телефона_заявка_не_создаётся(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), ['name' => 'Данил'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
    }

    public function test_короткий_номер_отклоняется(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['phone' => '123']))
            ->assertSessionHasErrors('phone');
    }

    /** Ловушка для ботов: заполненное скрытое поле — признак робота. */
    public function test_заполненная_ловушка_отсекает_бота(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['website' => 'http://spam']))
            ->assertSessionHasErrors('website');

        $this->assertSame(0, Order::count());
    }

    public function test_для_доставки_обязателен_адрес(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['fulfillment' => 'delivery']))
            ->assertSessionHasErrors('address');

        $this->post(route('order.store', $product), $this->valid([
            'fulfillment' => 'delivery',
            'address' => 'Алматы, Абая 1',
        ]))->assertSessionHasNoErrors();
    }

    public function test_на_неопубликованный_товар_заявку_не_оставить(): void
    {
        $product = Product::factory()->unpublished()->create();

        $this->post(route('order.store', $product), $this->valid())->assertNotFound();
    }

    public function test_номера_заказов_не_повторяются(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['phone' => '77001111111']));
        $this->post(route('order.store', $product), $this->valid(['phone' => '77002222222']));

        $this->assertSame(2, Order::distinct('number')->count('number'));
    }

    /** Страница «спасибо» показывает номер, но не даёт листать чужие заявки. */
    public function test_страница_спасибо_показывает_номер_из_сессии(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid());

        $this->get(route('order.thanks'))
            ->assertOk()
            ->assertSee(Order::sole()->number);
    }

    public function test_чужой_номер_через_адрес_не_подсмотреть(): void
    {
        Order::factory()->create(['number' => 'АС-000777']);

        $this->get(route('order.thanks'))
            ->assertOk()
            ->assertDontSee('АС-000777');
    }

    // ------------------------------------------------------------------
    // статусы
    // ------------------------------------------------------------------

    public function test_разрешённый_переход_статуса_выполняется(): void
    {
        $order = Order::factory()->status(OrderStatus::New)->create();

        $this->assertTrue($order->transitionTo(OrderStatus::InProgress));
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    /** Возврат из «Выполнен» — не сценарий, а ошибка. */
    public function test_запрещённый_переход_статуса_отклоняется(): void
    {
        $order = Order::factory()->status(OrderStatus::Completed)->create();

        $this->assertFalse($order->transitionTo(OrderStatus::New));
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_завершение_проставляет_дату(): void
    {
        $order = Order::factory()->status(OrderStatus::Confirmed)->create();

        $order->transitionTo(OrderStatus::Completed);

        $this->assertNotNull($order->fresh()->completed_at);
    }
}
