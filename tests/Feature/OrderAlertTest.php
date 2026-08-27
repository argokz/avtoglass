<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Cart\Cart;
use App\Enums\OrderSource;
use App\Jobs\AnnounceNewOrder;
use App\Models\Order;
use App\Notifications\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Сигнал о новой заявке.
 *
 * В этой нише клиент оставляет заявку сразу в нескольких местах, и продаёт
 * тот, кто перезвонил первым. Пятнадцатиминутный срок на звонок имеет смысл
 * только тогда, когда о заявке узнают в ту же минуту, а не при следующем
 * заходе в админку.
 */
class OrderAlertTest extends TestCase
{
    use RefreshDatabase;

    private function enableTelegram(): void
    {
        config([
            'notifications.telegram.enabled' => true,
            'notifications.telegram.token' => '123456:TEST',
            'notifications.telegram.chat_id' => '-1001234567890',
        ]);
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Данил',
            'phone' => '87015550001',
            'comment' => 'Разбито лобовое',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // постановка в очередь
    // ------------------------------------------------------------------

    public function test_новая_заявка_ставит_уведомление_в_очередь(): void
    {
        Queue::fake();

        app(CreateOrder::class)->handle($this->formData(), OrderSource::ProductPage);

        Queue::assertPushed(AnnounceNewOrder::class);
    }

    public function test_заявка_из_корзины_тоже_уведомляет(): void
    {
        Queue::fake();

        $cart = app(Cart::class);

        app(CreateOrder::class)->fromCart($this->formData(), $cart);

        Queue::assertPushed(AnnounceNewOrder::class);
    }

    // ------------------------------------------------------------------
    // само сообщение
    // ------------------------------------------------------------------

    public function test_в_сообщение_попадают_номер_и_телефон(): void
    {
        $this->enableTelegram();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $order = Order::factory()->create(['total_amount' => 42000]);
        $order->items()->create(['name' => 'Лобовое Camry', 'price' => 42000, 'quantity' => 1]);

        (new AnnounceNewOrder($order))->handle(app(TelegramClient::class));

        Http::assertSent(function (Request $request) use ($order) {
            $text = $request['text'];

            return str_contains($request->url(), 'sendMessage')
                && str_contains($text, $order->number)
                && str_contains($text, $order->customer->phone)
                && str_contains($text, 'Лобовое Camry');
        });
    }

    /** Срок звонка написан прямым текстом — по нему потом разбирают потери. */
    public function test_в_сообщении_есть_срок_звонка(): void
    {
        $this->enableTelegram();
        Http::fake(['*' => Http::response(['ok' => true])]);

        (new AnnounceNewOrder(Order::factory()->create()))->handle(app(TelegramClient::class));

        Http::assertSent(fn (Request $request) => str_contains($request['text'], '15 минут'));
    }

    // ------------------------------------------------------------------
    // отказоустойчивость
    // ------------------------------------------------------------------

    /** Без токена никуда не стучимся: на dev это был бы шум в логах. */
    public function test_без_настроек_ничего_не_отправляется(): void
    {
        config(['notifications.telegram.enabled' => false]);
        Http::fake();

        (new AnnounceNewOrder(Order::factory()->create()))->handle(app(TelegramClient::class));

        Http::assertNothingSent();
    }

    /**
     * Главное свойство: заявка не имеет права упасть из-за мессенджера.
     * Клиент уже нажал «отправить».
     */
    public function test_упавший_телеграм_не_ломает_заявку(): void
    {
        $this->enableTelegram();
        Http::fake(['*' => Http::response('', 500)]);

        $order = app(CreateOrder::class)->handle($this->formData(), OrderSource::ProductPage);

        $this->assertNotNull($order->number);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_клиент_видит_кнопку_вотсапа(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('wa.me/'.config('company.whatsapp'), escape: false);
    }
}
