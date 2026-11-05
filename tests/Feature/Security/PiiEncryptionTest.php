<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Персональные данные лежат в базе шифротекстом, а поиск по телефону
 * идёт через слепой индекс phone_hash.
 */
class PiiEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_телефон_хранится_шифротекстом_и_находится_по_хэшу(): void
    {
        $customer = Customer::forPhone('+7 700 123-45-67', 'Данил');

        $raw = DB::table('customers')->where('id', $customer->id)->first();

        $this->assertStringNotContainsString('77001234567', $raw->phone);
        $this->assertSame(Customer::phoneHash('77001234567'), $raw->phone_hash);

        // Через модель телефон читается как обычно.
        $this->assertSame('77001234567', $customer->fresh()->phone);

        // И тот же человек находится повторно — дубля карточки нет.
        $again = Customer::forPhone('8 700 123 45 67', 'Данил');
        $this->assertSame($customer->id, $again->id);
        $this->assertSame(1, Customer::count());
    }

    public function test_адрес_заказа_хранится_шифротекстом(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), $this->orderPayload([
            'fulfillment' => 'delivery',
            'address' => 'Караганда, Ленина 10',
        ]));

        $order = Order::sole();
        $raw = DB::table('orders')->where('id', $order->id)->first();

        $this->assertStringNotContainsString('Ленина', (string) $raw->address);
        $this->assertSame('Караганда, Ленина 10', $order->fresh()->address);
    }

    public function test_поиск_заявки_по_телефону_работает_через_хэш(): void
    {
        $product = Product::factory()->create();
        $this->post(route('order.store', $product), $this->orderPayload());

        $order = Order::sole();

        $this->post(route('orders.find'), [
            'phone' => '+7 (700) 123-45-67',
            'number' => $order->number,
        ])->assertRedirect(route('orders.mine'));
    }
}
