<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Gateways\FreedomPayGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Драйвер Freedom Pay.
 *
 * Главное здесь — подпись. Ошибка в ней выглядит на стороне банка как
 * «неверный секретный ключ», и искать её потом приходится вслепую,
 * поэтому схема закреплена тестами по шагам.
 */
class FreedomPayTest extends TestCase
{
    use RefreshDatabase;

    private FreedomPayGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.driver' => 'freedompay',
            'payments.freedompay.merchant_id' => '123456',
            'payments.freedompay.secret' => 'test-secret',
            'payments.freedompay.test_mode' => true,
        ]);

        $this->app->forgetInstance(PaymentGateway::class);
        $this->gateway = app(FreedomPayGateway::class);
    }

    private function payment(float $amount = 27000): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return Payment::factory()->for($order)->create([
            'provider' => 'freedompay',
            'amount' => $amount,
        ]);
    }

    private function xml(array $fields): string
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><response>';
        foreach ($fields as $k => $v) {
            $body .= "<{$k}>{$v}</{$k}>";
        }

        return $body.'</response>';
    }

    // ------------------------------------------------------------------
    // подпись
    // ------------------------------------------------------------------

    /** MD5 от «имя_скрипта;поля_по_алфавиту;секрет». */
    public function test_подпись_считается_по_схеме_банка(): void
    {
        $params = ['pg_b' => 'two', 'pg_a' => 'one'];

        $expected = md5('init_payment.php;one;two;test-secret');

        $this->assertSame($expected, $this->gateway->sign('init_payment.php', $params));
    }

    /** Порядок передачи полей не должен влиять — сортировка по ключу. */
    public function test_порядок_полей_не_меняет_подпись(): void
    {
        $a = $this->gateway->sign('x.php', ['pg_a' => '1', 'pg_b' => '2']);
        $b = $this->gateway->sign('x.php', ['pg_b' => '2', 'pg_a' => '1']);

        $this->assertSame($a, $b);
    }

    /** Сама подпись в расчёт не входит — иначе она зависела бы от себя. */
    public function test_подпись_не_участвует_в_собственном_расчёте(): void
    {
        $without = $this->gateway->sign('x.php', ['pg_a' => '1']);
        $with = $this->gateway->sign('x.php', ['pg_a' => '1', 'pg_sig' => 'что угодно']);

        $this->assertSame($without, $with);
    }

    /** Имя скрипта — часть подписи, и у запроса с колбэком оно разное. */
    public function test_имя_скрипта_меняет_подпись(): void
    {
        $this->assertNotSame(
            $this->gateway->sign('init_payment.php', ['pg_a' => '1']),
            $this->gateway->sign('callback', ['pg_a' => '1']),
        );
    }

    // ------------------------------------------------------------------
    // инициализация платежа
    // ------------------------------------------------------------------

    public function test_инициализация_отдаёт_ссылку_банка(): void
    {
        Http::fake(['*' => Http::response($this->xml([
            'pg_status' => 'ok',
            'pg_payment_id' => '987654',
            'pg_redirect_url' => 'https://api.freedompay.kz/pay.html?customer=abc',
        ]))]);

        $payment = $this->payment();

        $url = $this->gateway->redirectUrl($payment);

        $this->assertSame('https://api.freedompay.kz/pay.html?customer=abc', $url);
        // id на стороне банка сохраняем — по нему потом сверять колбэк
        $this->assertSame('987654', $payment->fresh()->external_id);
    }

    public function test_запрос_содержит_обязательные_поля_и_подпись(): void
    {
        Http::fake(['*' => Http::response($this->xml([
            'pg_status' => 'ok', 'pg_payment_id' => '1', 'pg_redirect_url' => 'https://pay',
        ]))]);

        $payment = $this->payment(27000);

        $this->gateway->redirectUrl($payment);

        Http::assertSent(function ($request) use ($payment) {
            $data = $request->data();

            return $data['pg_order_id'] === (string) $payment->id
                && $data['pg_merchant_id'] === '123456'
                && $data['pg_amount'] === '27000.00'
                && $data['pg_currency'] === 'KZT'
                && $data['pg_testing_mode'] === '1'
                && $data['pg_sig'] === $this->gateway->sign('init_payment.php', collect($data)->except('pg_sig')->all());
        });
    }

    /** Отказ банка не должен превращаться в битую ссылку. */
    public function test_отказ_банка_даёт_null_а_не_битую_ссылку(): void
    {
        Http::fake(['*' => Http::response($this->xml([
            'pg_status' => 'error',
            'pg_error_description' => 'Неверный секретный ключ',
        ]))]);

        $this->assertNull($this->gateway->redirectUrl($this->payment()));
    }

    public function test_недоступность_банка_даёт_null(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $this->assertNull($this->gateway->redirectUrl($this->payment()));
    }

    /** Клиента нельзя оставлять без объяснения, если касса не поднялась. */
    public function test_при_недоступной_кассе_клиент_видит_причину(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $order = Order::factory()->create(['total_amount' => 27000]);

        $this->withSession(['last_order_number' => $order->number])
            ->post(route('payment.start'))
            ->assertRedirect(route('order.thanks'))
            ->assertSessionHas('payment_error');
    }

    // ------------------------------------------------------------------
    // уведомление
    // ------------------------------------------------------------------

    private function callbackData(Payment $payment, array $override = []): array
    {
        $data = array_merge([
            'pg_order_id' => (string) $payment->id,
            'pg_payment_id' => '987654',
            'pg_result' => '1',
            'pg_amount' => '27000.00',
            'pg_salt' => 'abc123',
        ], $override);

        $data['pg_sig'] = $this->gateway->sign('callback', $data);

        return $data;
    }

    public function test_успешное_уведомление_проводит_оплату(): void
    {
        $payment = $this->payment(27000);

        $response = $this->post(route('payment.freedompay.callback'), $this->callbackData($payment));

        $response->assertOk();
        $this->assertStringContainsString('<pg_status>ok</pg_status>', $response->getContent());

        $payment->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('987654', $payment->external_id);
    }

    /** Ответ банку тоже подписан — иначе он посчитает, что мы не приняли. */
    public function test_ответ_банку_подписан(): void
    {
        $payment = $this->payment(27000);

        $response = $this->post(route('payment.freedompay.callback'), $this->callbackData($payment));

        $xml = simplexml_load_string($response->getContent());
        $fields = ['pg_status' => (string) $xml->pg_status, 'pg_salt' => (string) $xml->pg_salt];

        $this->assertSame($this->gateway->sign('callback', $fields), (string) $xml->pg_sig);
    }

    public function test_уведомление_с_битой_подписью_отклоняется(): void
    {
        $payment = $this->payment(27000);

        $data = $this->callbackData($payment);
        $data['pg_sig'] = str_repeat('0', 32);

        $this->post(route('payment.freedompay.callback'), $data)->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    /** Подпись верна, но сумма занижена — ловит общая проверка. */
    public function test_подмена_суммы_ловится(): void
    {
        $payment = $this->payment(27000);

        $this->post(route('payment.freedompay.callback'), $this->callbackData($payment, ['pg_amount' => '1.00']));

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('не совпала', $payment->failure_reason);
    }

    public function test_отказ_платежа_не_подтверждает_заказ(): void
    {
        $payment = $this->payment(27000);

        $this->post(route('payment.freedompay.callback'), $this->callbackData($payment, [
            'pg_result' => '0',
            'pg_failure_description' => 'Недостаточно средств',
        ]))->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Недостаточно средств', $payment->failure_reason);
    }

    /** pg_result=2 значит «клиент ещё не закончил» — платёж остаётся открытым. */
    public function test_незавершённый_платёж_остаётся_в_ожидании(): void
    {
        $payment = $this->payment(27000);

        $this->post(route('payment.freedompay.callback'), $this->callbackData($payment, ['pg_result' => '2']));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_возврат_без_pg_payment_id_не_ходит_в_банк(): void
    {
        Http::fake();

        $this->assertFalse($this->gateway->refund($this->payment()));
        Http::assertNothingSent();
    }

    public function test_возврат_подписывает_revoke(): void
    {
        $payment = $this->payment(15500);
        $payment->update(['external_id' => 'pg-42']);

        Http::fake([
            'https://api.freedompay.kz/revoke.php' => Http::response(
                '<?xml version="1.0"?><response><pg_status>ok</pg_status></response>'
            ),
        ]);

        $this->assertTrue($this->gateway->refund($payment));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'revoke.php')
                && ($data['pg_payment_id'] ?? null) === 'pg-42'
                && ($data['pg_refund_amount'] ?? null) === '15500.00'
                && filled($data['pg_sig'] ?? null);
        });
    }

    public function test_отказ_revoke_не_считается_успехом(): void
    {
        $payment = $this->payment();
        $payment->update(['external_id' => 'pg-42']);

        Http::fake([
            'https://api.freedompay.kz/revoke.php' => Http::response(
                '<?xml version="1.0"?><response><pg_status>error</pg_status><pg_error_description>denied</pg_error_description></response>'
            ),
        ]);

        $this->assertFalse($this->gateway->refund($payment));
    }
}
