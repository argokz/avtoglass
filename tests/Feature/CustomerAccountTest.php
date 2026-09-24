<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Кабинет покупателя.
 *
 * Главное здесь не удобство, а граница: регистрация идёт по телефону, SMS мы
 * не подтверждаем, и без отдельной проверки любой желающий заводил бы аккаунт
 * на чужой номер и читал чужие заказы. Поэтому карточка с историей привязывается
 * только после того, как человек назвал номер своей заявки.
 */
class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function данные(array $override = []): array
    {
        return array_merge([
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
            'password' => 'ochen-dlinnyi-parol',
            'password_confirmation' => 'ochen-dlinnyi-parol',
        ], $override);
    }

    // ------------------------------------------------------------------
    // регистрация
    // ------------------------------------------------------------------

    public function test_регистрация_создаёт_кабинет_покупателя(): void
    {
        $this->post(route('account.register.store'), $this->данные())
            ->assertRedirect(route('account.orders'));

        $user = User::sole();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertAuthenticatedAs($user);
    }

    /** Телефон приводится к одному виду: иначе войти по нему потом не получится. */
    public function test_телефон_сохраняется_в_нормальном_виде(): void
    {
        $this->post(route('account.register.store'), $this->данные(['phone' => '8 (700) 123 45 67']));

        $this->assertSame(
            Customer::normalizePhone('+7 700 123-45-67'),
            User::sole()->phone
        );
    }

    /** Почта необязательна: её заводят не все, а вход всё равно по телефону. */
    public function test_кабинет_заводится_без_почты(): void
    {
        $this->post(route('account.register.store'), $this->данные())
            ->assertRedirect(route('account.orders'));

        $this->assertNull(User::sole()->email);
    }

    public function test_два_кабинета_на_один_телефон_не_заводятся(): void
    {
        $this->post(route('account.register.store'), $this->данные());
        $this->post(route('account.logout'));

        $this->post(route('account.register.store'), $this->данные(['name' => 'Второй']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, User::count());
    }

    public function test_короткий_пароль_не_принимается(): void
    {
        $this->post(route('account.register.store'), $this->данные([
            'password' => 'korotk',
            'password_confirmation' => 'korotk',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_несовпадающие_пароли_не_принимаются(): void
    {
        $this->post(route('account.register.store'), $this->данные([
            'password_confirmation' => 'drugoi-parol-sovsem',
        ]))->assertSessionHasErrors('password');
    }

    // ------------------------------------------------------------------
    // чужая история
    // ------------------------------------------------------------------

    /**
     * Тот самый случай, ради которого всё это написано: человек регистрируется
     * на номер, по которому уже есть заказы. Аккаунт он получает, историю — нет.
     */
    public function test_регистрация_на_чужой_номер_историю_не_открывает(): void
    {
        $customer = Customer::factory()->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
        ]);
        $order = Order::factory()->for($customer)->create();

        $this->post(route('account.register.store'), $this->данные())
            ->assertRedirect(route('account.claim'));

        $this->assertNull(User::sole()->customer_id);

        $this->get(route('account.orders'))
            ->assertOk()
            ->assertDontSee($order->number);
    }

    /** Новый номер прятать не от кого — карточку привязываем сразу. */
    public function test_новый_номер_привязывается_сразу(): void
    {
        $this->post(route('account.register.store'), $this->данные())
            ->assertRedirect(route('account.orders'));

        $this->assertNotNull(User::sole()->customer_id);
    }

    /** Карточка без заказов тоже ничего не раскрывает. */
    public function test_карточка_без_заказов_привязывается_сразу(): void
    {
        Customer::factory()->create(['phone' => Customer::normalizePhone('+7 700 123-45-67')]);

        $this->post(route('account.register.store'), $this->данные())
            ->assertRedirect(route('account.orders'));

        $this->assertNotNull(User::sole()->customer_id);
    }

    public function test_номер_чужой_заявки_историю_не_открывает(): void
    {
        $user = $this->кабинетБезИстории();
        $чужой = Order::factory()->create(['number' => 'АС-000777']);

        $this->actingAs($user)
            ->post(route('account.claim.store'), ['number' => 'АС-000777'])
            ->assertSessionHasErrors('number');

        $this->assertNull($user->fresh()->customer_id);
        $this->assertNotNull($чужой->customer_id);
    }

    public function test_свой_номер_заявки_историю_открывает(): void
    {
        $user = $this->кабинетБезИстории();
        $order = Order::query()->sole();

        $this->actingAs($user)
            ->post(route('account.claim.store'), ['number' => $order->number])
            ->assertRedirect(route('account.orders'));

        $this->assertSame($order->customer_id, $user->fresh()->customer_id);

        $this->actingAs($user->fresh())
            ->get(route('account.orders'))
            ->assertOk()
            ->assertSee($order->number);
    }

    /** Номер называют на слух и переписывают как придётся — понимаем все виды. */
    public function test_номер_заявки_принимается_в_коротком_виде(): void
    {
        $user = $this->кабинетБезИстории();
        $order = Order::query()->sole();

        $this->actingAs($user)
            ->post(route('account.claim.store'), ['number' => (string) $order->id])
            ->assertRedirect(route('account.orders'));

        $this->assertNotNull($user->fresh()->customer_id);
    }

    // ------------------------------------------------------------------
    // вход
    // ------------------------------------------------------------------

    public function test_вход_по_телефону_и_паролю(): void
    {
        $user = $this->покупатель();

        $this->post(route('account.login.store'), [
            'phone' => '+7 700 123-45-67',
            'password' => 'ochen-dlinnyi-parol',
        ])->assertRedirect(route('account.orders'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_неверный_пароль_в_кабинет_не_пускает(): void
    {
        $this->покупатель();

        $this->post(route('account.login.store'), [
            'phone' => '+7 700 123-45-67',
            'password' => 'ne-tot-parol-sovsem',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    /**
     * Формулировка одна на оба случая. Разные тексты для «нет такого номера»
     * и «пароль не тот» дают перебором список клиентов сервиса.
     */
    public function test_несуществующий_номер_и_неверный_пароль_дают_один_ответ(): void
    {
        $this->покупатель();

        $один = 'Не подошли телефон или пароль';

        $this->post(route('account.login.store'), [
            'phone' => '+7 777 000-00-00',
            'password' => 'ochen-dlinnyi-parol',
        ])->assertSessionHasErrors(['phone' => $один]);

        $this->post(route('account.login.store'), [
            'phone' => '+7 700 123-45-67',
            'password' => 'ne-tot-parol-sovsem',
        ])->assertSessionHasErrors(['phone' => $один]);
    }

    /** Отключённый аккаунт не входит — тем же флагом, что и у сотрудников. */
    public function test_отключённый_покупатель_не_входит(): void
    {
        $this->покупатель()->update(['is_active' => false]);

        $this->post(route('account.login.store'), [
            'phone' => '+7 700 123-45-67',
            'password' => 'ochen-dlinnyi-parol',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    /** Сотрудник входит в панель по почте, а не в кабинет по телефону. */
    public function test_сотрудник_через_клиентский_вход_не_проходит(): void
    {
        User::factory()->manager()->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
            'password' => Hash::make('ochen-dlinnyi-parol'),
        ]);

        $this->post(route('account.login.store'), [
            'phone' => '+7 700 123-45-67',
            'password' => 'ochen-dlinnyi-parol',
        ])->assertSessionHasErrors('phone');

        $this->assertGuest();
    }

    public function test_выход_разлогинивает(): void
    {
        $this->actingAs($this->покупатель())
            ->post(route('account.logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    // доступ
    // ------------------------------------------------------------------

    /** Гостя с кабинета отправляем на клиентский вход, а не в админку. */
    public function test_гость_попадает_на_клиентский_вход(): void
    {
        $this->get(route('account.orders'))->assertRedirect(route('account.login'));
    }

    /** Кабинет менеджеру не нужен и выглядел бы поломкой: карточки у него нет. */
    public function test_сотрудника_в_кабинет_не_пускают(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('account.orders'))
            ->assertRedirect(route('home'));
    }

    /** Обратная сторона: покупатель в админку не попадает. */
    public function test_покупателя_в_админку_не_пускают(): void
    {
        $this->actingAs($this->покупатель())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_вошедшего_со_страницы_входа_уводит_в_кабинет(): void
    {
        $this->actingAs($this->покупатель())
            ->get(route('account.login'))
            ->assertRedirect(route('account.orders'));
    }

    // ------------------------------------------------------------------
    // заказ без регистрации
    // ------------------------------------------------------------------

    /**
     * Главное свойство всей этой пары страниц: они ничего не ломают.
     * Заявка оформляется без кабинета, как и раньше.
     */
    public function test_заявка_оформляется_без_регистрации(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->orderPayload())
            ->assertRedirect(route('order.thanks'));

        $this->assertSame(1, Order::count());
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /** Вошедшему подставляем его данные — ради этого кабинет и заводят. */
    public function test_форма_заказа_предзаполнена_для_вошедшего(): void
    {
        $user = $this->покупатель();

        $this->actingAs($user)
            ->get(route('order.form'))
            ->assertOk()
            ->assertSee('value="'.$user->name.'"', false)
            ->assertSee('value="'.$user->phone.'"', false);
    }

    /** Адрес из карточки клиента попадает в форму доставки. */
    public function test_форма_подставляет_сохранённый_адрес(): void
    {
        $user = $this->покупательСКарточкой([
            'default_address' => 'ул. Абая 10, кв. 5',
            'preferred_fulfillment' => 'delivery',
        ]);

        $this->actingAs($user)
            ->get(route('order.form'))
            ->assertOk()
            ->assertSee('value="ул. Абая 10, кв. 5"', false)
            ->assertSee('Адрес из прошлого заказа', false);
    }

    /** После заявки с доставкой адрес остаётся на карточке. */
    public function test_заказ_запоминает_адрес_доставки(): void
    {
        $product = Product::factory()->create();
        $user = $this->покупательСКарточкой();

        $this->actingAs($user)
            ->post(route('order.store', $product), $this->orderPayload([
                'name' => $user->name,
                'phone' => '+7 700 123-45-67',
                'fulfillment' => 'delivery',
                'address' => 'пр. Бухар-жырау 15',
            ]))
            ->assertRedirect(route('order.thanks'));

        $this->assertSame('пр. Бухар-жырау 15', $user->customer->fresh()->default_address);
        $this->assertSame('delivery', $user->customer->fresh()->preferred_fulfillment);
    }

    /**
     * Вошедший с тем же телефоном сразу видит новый заказ в кабинете —
     * без отдельного шага «привязать историю».
     */
    public function test_заказ_вошедшего_привязывает_кабинет(): void
    {
        $product = Product::factory()->create();
        $user = $this->покупатель(); // без customer_id

        $this->assertNull($user->customer_id);

        $this->actingAs($user)
            ->post(route('order.store', $product), $this->orderPayload([
                'name' => $user->name,
                'phone' => '+7 700 123-45-67',
            ]))
            ->assertRedirect(route('order.thanks'));

        $user->refresh();
        $this->assertNotNull($user->customer_id);
        $this->assertSame(1, $user->orders()->count());
    }

    public function test_профиль_сохраняет_адрес_и_имя(): void
    {
        $user = $this->покупательСКарточкой();

        $this->actingAs($user)
            ->post(route('account.profile.update'), [
                'name' => 'Данил Обновлённый',
                'email' => 'danil@example.com',
                'default_address' => 'ул. Ермекова 42',
                'default_city' => 'Караганда',
                'vin' => 'XTA211440Y0123456',
                'car_plate' => '123ABC01',
            ])
            ->assertRedirect(route('account.profile'));

        $user->refresh();
        $this->assertSame('Данил Обновлённый', $user->name);
        $this->assertSame('danil@example.com', $user->email);
        $this->assertSame('ул. Ермекова 42', $user->customer->fresh()->default_address);
        $this->assertSame('Караганда', $user->customer->fresh()->default_city);
        $this->assertSame('XTA211440Y0123456', $user->customer->fresh()->vin);
    }

    public function test_гость_на_профиль_не_попадает(): void
    {
        $this->get(route('account.profile'))->assertRedirect(route('account.login'));
    }

    /** После заявки регистрация подставляет телефон и имя — меньше полей руками. */
    public function test_регистрация_подставляет_данные_последней_заявки(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->orderPayload([
            'name' => 'Айгуль',
            'phone' => '+7 700 555-11-22',
        ]))->assertRedirect(route('order.thanks'));

        $phone = Customer::normalizePhone('+7 700 555-11-22');

        $this->get(route('account.register'))
            ->assertOk()
            ->assertSee('value="Айгуль"', false)
            ->assertSee('value="'.$phone.'"', false)
            ->assertSee('Подставили из последней заявки', false);
    }

    // ------------------------------------------------------------------

    private function покупатель(): User
    {
        return User::factory()->create([
            'role' => UserRole::Customer,
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
            'password' => Hash::make('ochen-dlinnyi-parol'),
            'is_active' => true,
        ]);
    }

    private function покупательСКарточкой(array $customerAttrs = []): User
    {
        $customer = Customer::factory()->create(array_merge([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
            'name' => 'Данил',
        ], $customerAttrs));

        return User::factory()->create([
            'role' => UserRole::Customer,
            'name' => 'Данил',
            'phone' => $customer->phone,
            'password' => Hash::make('ochen-dlinnyi-parol'),
            'is_active' => true,
            'customer_id' => $customer->id,
        ]);
    }

    /** Кабинет, заведённый на номер, по которому уже есть заказ. */
    private function кабинетБезИстории(): User
    {
        $customer = Customer::factory()->create([
            'phone' => Customer::normalizePhone('+7 700 123-45-67'),
        ]);
        Order::factory()->for($customer)->create();

        $this->post(route('account.register.store'), $this->данные());
        $user = User::sole();
        $this->post(route('account.logout'));

        return $user;
    }
}
