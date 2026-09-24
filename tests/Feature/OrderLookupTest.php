<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Кабинет» без регистрации: телефон + номер заявки.
 *
 * Одного номера мало — АС-000123 угадывается. Одного телефона мало —
 * по нему видны все заказы человека. Вместе это то, что клиент и так
 * называет менеджеру по звонку.
 */
class OrderLookupTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(string $phone, string $name = 'Данил'): Order
    {
        $customer = Customer::factory()->create([
            'name' => $name,
            'phone' => $phone,
        ]);

        return Order::factory()->for($customer)->create();
    }

    public function test_форма_поиска_открывается(): void
    {
        $this->get(route('orders.lookup'))
            ->assertOk()
            ->assertSee('Найти заявку')
            ->assertSee('Номер заявки');
    }

    public function test_телефон_и_номер_открывают_список_заявок(): void
    {
        $order = $this->orderFor('77015550001');

        $this->from(route('orders.lookup'))
            ->post(route('orders.find'), [
                'phone' => '+7 701 555-00-01',
                'number' => $order->number,
            ])
            ->assertRedirect(route('orders.mine'));

        $this->get(route('orders.mine'))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Данил');
    }

    public function test_номер_цифрами_тоже_принимается(): void
    {
        $order = $this->orderFor('77015550002');
        $digits = (string) $order->id;

        $this->post(route('orders.find'), [
            'phone' => '77015550002',
            'number' => $digits,
        ])->assertRedirect(route('orders.mine'));
    }

    public function test_латинский_префикс_ac_считается_тем_же_номером(): void
    {
        $order = $this->orderFor('77015550003');
        $this->post(route('orders.find'), [
            'phone' => '77015550003',
            'number' => 'AC-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
        ])->assertRedirect(route('orders.mine'));
    }

    public function test_чужой_телефон_к_заявке_не_подходит(): void
    {
        $order = $this->orderFor('77015550004');

        $this->from(route('orders.lookup'))
            ->post(route('orders.find'), [
                'phone' => '77019999999',
                'number' => $order->number,
            ])
            ->assertRedirect(route('orders.lookup'))
            ->assertSessionHasErrors('number');

        $this->get(route('orders.mine'))
            ->assertRedirect(route('orders.lookup'));
    }

    public function test_список_не_показывает_чужие_заявки(): void
    {
        $mine = $this->orderFor('77015550005');
        $this->orderFor('77016666666', 'Чужой');

        $this->post(route('orders.find'), [
            'phone' => '77015550005',
            'number' => $mine->number,
        ]);

        $this->get(route('orders.mine'))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee('Чужой');
    }

    public function test_без_сессии_список_не_открывается(): void
    {
        $this->get(route('orders.mine'))
            ->assertRedirect(route('orders.lookup'));
    }

    public function test_выход_забывает_телефон(): void
    {
        $order = $this->orderFor('77015550006');

        $this->post(route('orders.find'), [
            'phone' => '77015550006',
            'number' => $order->number,
        ]);

        $this->post(route('orders.logout'))
            ->assertRedirect(route('orders.lookup'));

        $this->get(route('orders.mine'))
            ->assertRedirect(route('orders.lookup'));
    }

    public function test_ловушка_для_ботов_на_поиске_работает(): void
    {
        $order = $this->orderFor('77015550007');

        $this->from(route('orders.lookup'))
            ->post(route('orders.find'), [
                'phone' => '77015550007',
                'number' => $order->number,
                'website' => 'http://spam',
            ])
            ->assertSessionHasErrors('website');
    }
}
