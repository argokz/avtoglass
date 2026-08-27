<?php

namespace Tests\Feature;

use App\Cart\Cart;
use App\Enums\OrderSource;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корзина.
 *
 * Опасность здесь не в арифметике, а в рассинхроне: между «положил» и
 * «оформил» проходит время, за которое прайс переливается, а карточка может
 * уехать с витрины. Корзина обязана это показать, а не молча продать
 * по цене прошлой недели.
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    private function cart(): Cart
    {
        return app(Cart::class);
    }

    private function product(float $price = 30000): Product
    {
        return Product::factory()->create(['price_min' => $price, 'price_max' => $price]);
    }

    // ------------------------------------------------------------------
    // сама корзина
    // ------------------------------------------------------------------

    public function test_товар_добавляется_и_считается(): void
    {
        $cart = $this->cart();
        $product = $this->product(30000);

        $cart->add($product, 2);

        $this->assertSame(2, $cart->count());
        $this->assertSame(60000.0, $cart->total());
    }

    public function test_повторное_добавление_увеличивает_количество(): void
    {
        $cart = $this->cart();
        $product = $this->product();

        $cart->add($product, 1);
        $cart->add($product, 2);

        $this->assertSame(3, $cart->count());
        $this->assertCount(1, $cart->items(), 'позиция должна остаться одна');
    }

    /** Стекло объёмное: сотня штук — это ошибка ввода, а не опт. */
    public function test_количество_ограничено_сверху(): void
    {
        $cart = $this->cart();

        $cart->add($this->product(), 500);

        $this->assertSame(Cart::MAX_QUANTITY, $cart->count());
    }

    public function test_нулевое_количество_убирает_позицию(): void
    {
        $cart = $this->cart();
        $product = $this->product();

        $cart->add($product, 3);
        $cart->setQuantity($product, 0);

        $this->assertTrue($cart->isEmpty());
    }

    public function test_неопубликованный_товар_в_корзину_не_кладётся(): void
    {
        $cart = $this->cart();

        $cart->add(Product::factory()->unpublished()->create());

        $this->assertTrue($cart->isEmpty());
    }

    /**
     * Между «положил» и «оформил» карточка может уехать с витрины.
     * Предлагать оформить то, чего уже нет, нельзя.
     */
    public function test_ушедший_с_витрины_товар_выпадает_из_корзины(): void
    {
        $cart = $this->cart();

        $staying = $this->product(30000);
        $leaving = $this->product(20000);

        $cart->add($staying);
        $cart->add($leaving);

        $leaving->update(['is_published' => false]);

        $this->assertCount(1, $cart->items());
        $this->assertSame(30000.0, $cart->total());
    }

    /** Цена берётся из карточки каждый раз, а не запоминается при добавлении. */
    public function test_корзина_показывает_текущую_цену_а_не_старую(): void
    {
        $cart = $this->cart();
        $product = $this->product(30000);

        $cart->add($product);
        $product->update(['price_min' => 35000]);

        $this->assertSame(35000.0, $cart->total());
    }

    public function test_позиции_без_цены_помечаются(): void
    {
        $cart = $this->cart();

        $cart->add($this->product(30000));
        $cart->add(Product::factory()->priced(null)->create());

        $this->assertTrue($cart->hasItemsWithoutPrice());
        $this->assertSame(30000.0, $cart->total(), 'позиция без цены не должна ломать итог');
    }

    // ------------------------------------------------------------------
    // страницы
    // ------------------------------------------------------------------

    public function test_пустая_корзина_открывается_и_подсказывает(): void
    {
        $this->get(route('cart.show'))
            ->assertOk()
            ->assertSee('Пока пусто');
    }

    public function test_добавление_через_форму_работает(): void
    {
        $product = $this->product(30000);

        $this->from(route('catalog.product', $product))
            ->post(route('cart.add', $product), ['quantity' => 2])
            ->assertRedirect(route('catalog.product', $product));

        $this->get(route('cart.show'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('60 000');
    }

    public function test_с_телефона_кнопка_ведёт_сразу_в_корзину(): void
    {
        $product = $this->product();

        $this->post(route('cart.add', $product), ['go_to_cart' => 1])
            ->assertRedirect(route('cart.show'));
    }

    public function test_позицию_можно_убрать(): void
    {
        $product = $this->product();

        $this->post(route('cart.add', $product));
        $this->from(route('cart.show'))->delete(route('cart.remove', $product));

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_в_корзину_нельзя_положить_снятый_с_витрины(): void
    {
        $product = Product::factory()->unpublished()->create();

        $this->post(route('cart.add', $product))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // оформление
    // ------------------------------------------------------------------

    /** Корзина — это одна заявка с позициями, а не несколько заявок. */
    public function test_вся_корзина_оформляется_одной_заявкой(): void
    {
        $first = $this->product(30000);
        $second = $this->product(20000);

        $this->post(route('cart.add', $first), ['quantity' => 2]);
        $this->post(route('cart.add', $second));

        $this->post(route('cart.checkout'), [
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ])->assertRedirect(route('order.thanks'));

        $order = Order::sole();

        $this->assertSame(OrderSource::Cart, $order->source);
        $this->assertCount(2, $order->items);
        $this->assertSame('80000.00', $order->total_amount);
    }

    public function test_количество_переносится_в_заказ(): void
    {
        $product = $this->product(30000);

        $this->post(route('cart.add', $product), ['quantity' => 3]);
        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '+7 700 123-45-67']);

        $item = Order::sole()->items()->sole();

        $this->assertSame(3, $item->quantity);
        $this->assertSame('30000.00', $item->price);
    }

    /** Цена фиксируется снимком: дальше пусть прайс меняется как угодно. */
    public function test_цена_фиксируется_в_момент_оформления(): void
    {
        $product = $this->product(30000);

        $this->post(route('cart.add', $product));
        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '+7 700 123-45-67']);

        $product->update(['price_min' => 99000, 'name' => 'Другое имя']);

        $item = Order::sole()->items()->sole();

        $this->assertSame('30000.00', $item->price);
        $this->assertNotSame('Другое имя', $item->name);
    }

    public function test_после_оформления_корзина_пуста(): void
    {
        $this->post(route('cart.add', $this->product()));
        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '+7 700 123-45-67']);

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_пустую_корзину_не_оформить(): void
    {
        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '+7 700 123-45-67'])
            ->assertRedirect(route('cart.show'));

        $this->assertSame(0, Order::count());
    }

    /** Одна заявка — один звонок клиенту, а не по звонку на позицию. */
    public function test_на_заявку_из_корзины_ставится_одна_задача(): void
    {
        $this->post(route('cart.add', $this->product(30000)));
        $this->post(route('cart.add', $this->product(20000)));
        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '+7 700 123-45-67']);

        $this->assertSame(1, Order::sole()->tasks()->count());
    }

    public function test_заявка_из_корзины_проходит_проверку_телефона(): void
    {
        $this->post(route('cart.add', $this->product()));

        $this->post(route('cart.checkout'), ['name' => 'Данил', 'phone' => '123'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Order::count());
        $this->assertFalse($this->cart()->isEmpty(), 'корзина не должна очищаться при ошибке');
    }
}
