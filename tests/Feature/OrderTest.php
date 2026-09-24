<?php

namespace Tests\Feature;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function valid(array $override = []): array
    {
        return $this->orderPayload($override);
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

    public function test_для_выезда_обязателен_адрес(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['fulfillment' => 'visit']))
            ->assertSessionHasErrors('address');
    }

    public function test_оплата_картой_сразу_открывает_halyk(): void
    {
        $product = Product::factory()->create(['price_min' => 27000, 'is_published' => true]);
        Offer::factory()->create(['product_id' => $product->id, 'price_retail' => 27000]);

        $response = $this->post(route('order.store', $product), $this->valid([
            'fulfillment' => 'pickup',
            'payment_method' => PaymentMethod::Card->value,
        ]));

        $payment = Payment::sole();

        $this->assertSame('halyk', $payment->provider);
        $this->assertSame(PaymentMethod::Card, Order::sole()->payment_method);
        $response->assertRedirect(route('payment.halyk', $payment));
    }

    public function test_на_форме_есть_способы_получения_и_оплаты(): void
    {
        // Форма живёт на /podbor и в корзине; с карточки товара при
        // включённой корзине её убрали, чтобы не дублировать заявку.
        $this->get(route('order.form'))
            ->assertOk()
            ->assertSee('Замена в сервисе')
            ->assertSee('Установка с выездом')
            ->assertSee('Доставка стекла')
            ->assertSee('Самовывоз')
            ->assertSee('Картой онлайн')
            ->assertSee('При получении');
    }

    /** Повтор той же формы (F5 / двойной клик) не плодит вторую заявку. */
    public function test_повтор_с_тем_же_токеном_не_создаёт_дубль(): void
    {
        $product = Product::factory()->create();
        $payload = $this->valid(['form_token' => 'tok-product-1']);

        $this->post(route('order.store', $product), $payload)
            ->assertRedirect(route('order.thanks'));

        $this->post(route('order.store', $product), $payload)
            ->assertRedirect(route('order.thanks'))
            ->assertSessionHas('order_deduped', true);

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Order::sole()->tasks()->count());
        $this->assertSame(2, Order::sole()->interactions()->count());
    }

    /** Новый токен — осознанная вторая заявка, её пропускаем. */
    public function test_новый_токен_создаёт_вторую_заявку(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['form_token' => 'tok-a']));
        $this->post(route('order.store', $product), $this->valid(['form_token' => 'tok-b']));

        $this->assertSame(2, Order::count());
    }

    /** Без токена поведение прежнее: каждый POST — новая заявка. */
    public function test_без_токена_дубли_не_блокируются(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid());
        $this->post(route('order.store', $product), $this->valid());

        $this->assertSame(2, Order::count());
    }

    public function test_при_включённой_корзине_на_карточке_быстрый_заказ_без_блока_заявки(): void
    {
        $product = Product::factory()->create();

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('Быстрый заказ')
            ->assertSee('Сразу к оформлению в корзине')
            ->assertSee('В корзину')
            ->assertDontSee('id="zayavka"', false)
            ->assertDontSee('Оставьте заявку на это стекло');
    }

    public function test_без_корзины_на_карточке_остаётся_блок_заявки(): void
    {
        settings()->save(['cart' => false]);

        $product = Product::factory()->create();

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('id="zayavka"', false)
            ->assertSee('Оставьте заявку на это стекло')
            ->assertDontSee('Быстрый заказ');
    }

    /**
     * Город сейчас один, но правило проверяем на двух: вторую точку откроют,
     * и заявка должна помнить, куда человек собрался приехать. Список задаём
     * здесь же — тест про механику, а не про сегодняшнюю географию.
     */
    public function test_заявка_запоминает_город_витрины(): void
    {
        settings()->save(['cities' => 'Караганда
Астана']);

        $product = Product::factory()->create();

        $this->from(route('home'))
            ->post(route('city.select'), ['city' => 'Астана'])
            ->assertRedirect();

        $this->post(route('order.store', $product), $this->valid([
            'fulfillment' => 'delivery',
            'address' => 'Сарайшык 1',
        ]))->assertRedirect(route('order.thanks'));

        $this->assertSame('Астана', Order::sole()->city);
    }

    public function test_без_выбора_города_пишется_город_по_умолчанию(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid())
            ->assertRedirect(route('order.thanks'));

        $this->assertSame('Караганда', Order::sole()->city);
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

    public function test_согласие_на_персональные_данные_сохраняется(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid());

        $this->assertNotNull(Order::sole()->privacy_accepted_at);
    }

    public function test_без_согласия_на_персональные_данные_заявку_не_принять(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid(['privacy_consent' => null]))
            ->assertSessionHasErrors('privacy_consent');

        $this->assertSame(0, Order::count());
    }

    public function test_vin_из_сессии_сохраняется_клиенту(): void
    {
        session(['vin' => 'JTDBR32E560012345']);
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->valid());

        $this->assertSame('JTDBR32E560012345', Customer::sole()->vin);
    }

    public function test_статус_заявки_открывается_по_токену_а_не_по_номеру(): void
    {
        $this->post(route('order.store', Product::factory()->create()), $this->valid());

        $order = Order::sole();

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('перезваниваем в течение рабочего дня')
            ->assertSee('Новая');

        $this->assertNotSame($order->number, $order->public_token);
        $this->assertStringNotContainsString($order->number, route('order.track', $order));
    }

    /**
     * Оплата видна клиенту на его же странице. Пока её там не было, первый
     * вопрос после списания был «деньги дошли?» — и его задавали менеджеру.
     */
    public function test_на_странице_статуса_видно_что_заказ_оплачен(): void
    {
        $order = Order::factory()->create();
        $order->payments()->create([
            'provider' => 'test',
            'status' => PaymentStatus::Succeeded,
            'amount' => 38630,
            'currency' => 'KZT',
        ]);

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee('Оплачено')
            ->assertSee('38 630 ₸');
    }

    /** Неоплаченный заказ не должен показывать строку оплаты вовсе. */
    public function test_неоплаченный_заказ_строку_оплаты_не_показывает(): void
    {
        $this->get(route('order.track', Order::factory()->create()))
            ->assertOk()
            ->assertDontSee('Оплачено');
    }

    /**
     * Сотрудника уволили и удалили, а вкладка у него осталась открытой —
     * и на этой сессии падало оформление заказа: Auth::id() отдаёт номер
     * из сессии даже тогда, когда самого пользователя уже нет, и запись
     * в журнал взаимодействий не проходила по внешнему ключу.
     */
    public function test_заказ_оформляется_даже_с_сессией_удалённого_сотрудника(): void
    {
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);
        User::query()->whereKey($manager->getKey())->delete();

        $this->post(route('order.store', Product::factory()->create()), $this->valid())
            ->assertRedirect(route('order.thanks'));

        $this->assertSame(1, Order::count());
        $this->assertNull(Interaction::query()->latest('id')->first()?->user_id);
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
