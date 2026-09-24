<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Cart\Cart;
use App\Enums\OrderSource;
use App\Jobs\AnnounceNewOrder;
use App\Mail\NewOrderAlert;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Notifications\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    public function test_запись_в_телеграме_содержит_время_и_сервис(): void
    {
        $this->enableTelegram();
        Http::fake(['*' => Http::response(['ok' => true])]);

        $point = ServicePoint::factory()->create(['name' => 'Сервис на Аэрологической']);
        $order = Order::factory()->create([
            'source' => OrderSource::Booking,
            'scheduled_at' => '2026-09-07 12:00:00',
        ]);
        Assignment::factory()->create([
            'order_id' => $order->id,
            'service_point_id' => $point->id,
            'scheduled_at' => '2026-09-07 12:00:00',
        ]);

        (new AnnounceNewOrder($order))->handle(app(TelegramClient::class));

        Http::assertSent(function (Request $request) {
            $text = $request['text'];

            return str_contains($text, 'Запись')
                && str_contains($text, 'Сервис на Аэрологической')
                && str_contains($text, 'уже выбрал время');
        });
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

    public function test_на_спасибо_есть_сообщение_заявки_в_вотсап(): void
    {
        $order = Order::factory()->create(['total_amount' => 27000]);

        $this->withSession(['last_order_number' => $order->number])
            ->get(route('order.thanks'))
            ->assertOk()
            ->assertSee('Отправить заказ в WhatsApp')
            ->assertSee($order->number, escape: false)
            ->assertSee('wa.me/', escape: false);
    }

    public function test_заявка_уходит_в_вотсап_если_настроен_green_api(): void
    {
        config([
            'notifications.whatsapp.green_instance' => '123',
            'notifications.whatsapp.green_token' => 'token',
            'notifications.whatsapp.chat_id' => '77015550000',
        ]);
        Http::fake(['*' => Http::response(['idMessage' => 'abc'], 200)]);

        $order = Order::factory()->create();

        (new AnnounceNewOrder($order))->handle(app(TelegramClient::class), app(\App\Notifications\WhatsAppClient::class));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'green-api.com')
            && str_contains($request['message'], $order->number));
    }

    public function test_заявка_уходит_на_почту(): void
    {
        Mail::fake();

        $order = Order::factory()->create(['total_amount' => 42000]);
        $order->items()->create(['name' => 'Лобовое Camry', 'price' => 42000, 'quantity' => 1]);

        (new AnnounceNewOrder($order))->handle(app(TelegramClient::class));

        Mail::assertSent(NewOrderAlert::class, function (NewOrderAlert $mail) use ($order) {
            return $mail->hasTo(settings()->email())
                && str_contains($mail->envelope()->subject, $order->number)
                && str_contains($mail->render(), 'Лобовое Camry')
                && str_contains($mail->render(), $order->customer->phone);
        });
    }

    public function test_выключенные_письма_не_уходят(): void
    {
        settings()->save(['email_alerts' => false]);
        Mail::fake();

        (new AnnounceNewOrder(Order::factory()->create()))->handle(app(TelegramClient::class));

        Mail::assertNothingSent();
    }

    public function test_письмо_идёт_на_ящик_из_env(): void
    {
        config(['notifications.email.to' => 'smena@avto-glass.kz']);
        Mail::fake();

        (new AnnounceNewOrder(Order::factory()->create()))->handle(app(TelegramClient::class));

        Mail::assertSent(NewOrderAlert::class, fn (NewOrderAlert $mail) => $mail->hasTo('smena@avto-glass.kz'));
    }
}
