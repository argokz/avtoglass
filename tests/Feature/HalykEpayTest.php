<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\Gateways\HalykEpayGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Драйвер Halyk ePay.
 *
 * Сеть в тестах не трогаем: живой стенд банка проверяется отдельным скриптом,
 * а здесь важно, что мы шлём и как разбираем ответ. Тест, зависящий от чужого
 * сервера, падает не тогда, когда сломались мы.
 */
class HalykEpayTest extends TestCase
{
    use RefreshDatabase;

    private HalykEpayGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['payments.driver' => 'halyk']);
        $this->app->forgetInstance(PaymentGateway::class);

        $this->gateway = app(HalykEpayGateway::class);
    }

    private function payment(float $amount = 27000): Payment
    {
        $order = Order::factory()->create(['total_amount' => $amount]);

        return Payment::factory()->for($order)->create([
            'provider' => 'halyk',
            'amount' => $amount,
        ]);
    }

    /** Секрет платежа выводится из его токена — снаружи его не подобрать. */
    private function secretHash(Payment $payment): string
    {
        return hash_hmac('sha256', $payment->token, (string) config('app.key'));
    }

    // ------------------------------------------------------------------
    // получение токена
    // ------------------------------------------------------------------

    public function test_запрос_токена_содержит_обязательные_поля_банка(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'ABC123'], 200)]);

        $payment = $this->payment(27000);

        $auth = $this->gateway->authorize($payment);

        $this->assertSame('ABC123', $auth['token']);
        $this->assertMatchesRegularExpression('/^\d{15}$/', $auth['invoice_id']);

        Http::assertSent(function ($request) use ($auth) {
            $data = $request->data();

            return $data['grant_type'] === 'client_credentials'
                && $data['terminal'] === config('payments.halyk.terminal')
                && $data['invoiceID'] === $auth['invoice_id']
                // Банк принимает сумму целым числом
                && $data['amount'] === 27000
                && $data['currency'] === 'KZT'
                && str_contains($data['postLink'], '/oplata/halyk/callback');
        });
    }

    /**
     * Песочница общая: короткий id платежа там уже занят, касса отвечает
     * «время оплаты истекло». Номер счёта должен быть 15 цифр и не совпадать
     * с id, а повторный токен — брать новый номер.
     */
    public function test_номер_счёта_не_повторяет_короткий_id_платежа(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'T'], 200)]);

        $payment = $this->payment();
        $first = $this->gateway->authorize($payment);
        $second = $this->gateway->authorize($payment->fresh());

        $this->assertMatchesRegularExpression('/^\d{15}$/', $first['invoice_id']);
        $this->assertNotSame(str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), $first['invoice_id']);
        $this->assertNotSame($first['invoice_id'], $second['invoice_id']);
        $this->assertSame($second['invoice_id'], $this->gateway->invoiceId($payment->fresh()));
    }

    /** Молчаливый провал означал бы пустую страницу вместо формы карты. */
    public function test_отказ_банка_в_токене_не_молчит(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->assertNull($this->gateway->authorize($this->payment()));
    }

    public function test_страница_виджета_показывает_сумму(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'ABC123'], 200)]);

        $payment = $this->payment(27000);

        $this->get(route('payment.halyk', $payment))
            ->assertOk()
            ->assertSee('27 000')
            ->assertSee('4405 6397 0401 5096')
            ->assertSee('test-epay.epayment.kz', false)
            ->assertSee('payment-api.js', false)
            ->assertSee('ABC123', false);
    }

    public function test_колбэк_находит_платёж_по_выданному_номеру_счёта(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'T'], 200)]);

        $payment = $this->payment(27000);
        $auth = $this->gateway->authorize($payment);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'id' => 'halyk-tx-minted',
            'invoiceId' => $auth['invoice_id'],
            'amount' => 27000,
            'secret_hash' => $this->secretHash($payment),
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame('halyk-tx-minted', $payment->fresh()->external_id);
    }

    public function test_при_недоступной_кассе_клиента_не_бросают_на_пустой_странице(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $payment = $this->payment();

        $this->get(route('payment.halyk', $payment))
            ->assertRedirect(route('payment.result', $payment))
            ->assertSessionHas('payment_error');
    }

    // ------------------------------------------------------------------
    // уведомление банка
    // ------------------------------------------------------------------

    public function test_успешное_уведомление_проводит_оплату(): void
    {
        $payment = $this->payment(27000);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'reason' => 'success',
            'id' => 'halyk-tx-1',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 27000,
            'secret_hash' => $this->secretHash($payment),
        ])->assertOk()->assertJson(['status' => 'ok']);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('halyk-tx-1', $payment->external_id);
    }

    /**
     * secret_hash — единственное, что отличает уведомление банка от чужого
     * запроса: своей подписи у postLink нет.
     */
    public function test_уведомление_с_чужим_secret_hash_отклоняется(): void
    {
        $payment = $this->payment(27000);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 27000,
            'secret_hash' => str_repeat('f', 64),
        ])->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_уведомление_без_secret_hash_отклоняется(): void
    {
        $payment = $this->payment(27000);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 27000,
        ])->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_неизвестный_номер_счёта_отклоняется(): void
    {
        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'invoiceId' => '999999',
            'amount' => 100,
            'secret_hash' => str_repeat('a', 64),
        ])->assertStatus(400);
    }

    public function test_отказ_банка_не_проводит_оплату(): void
    {
        $payment = $this->payment(27000);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'error',
            'reason' => 'Недостаточно средств',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 27000,
            'secret_hash' => $this->secretHash($payment),
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Недостаточно средств', $payment->failure_reason);
    }

    /**
     * Сумму в CallbackResult кладём из уведомления, а не свою, — иначе
     * общая проверка на подмену суммы окажется выключенной для этого драйвера.
     */
    public function test_подмена_суммы_ловится_общей_проверкой(): void
    {
        $payment = $this->payment(27000);

        $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 1,
            'secret_hash' => $this->secretHash($payment),
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('не совпала', $payment->failure_reason);
    }

    /** Банк ждёт короткий ответ, а не редирект: по нему никто не пойдёт. */
    public function test_ответ_банку_это_json_а_не_редирект(): void
    {
        $payment = $this->payment(27000);

        $response = $this->postJson(route('payment.halyk.callback'), [
            'code' => 'ok',
            'invoiceId' => $this->gateway->invoiceId($payment),
            'amount' => 27000,
            'secret_hash' => $this->secretHash($payment),
        ]);

        $response->assertOk();
        $this->assertJson($response->getContent());
    }

    public function test_песочница_включена_по_умолчанию(): void
    {
        $this->assertTrue((bool) config('payments.halyk.test_mode'));
        $this->assertSame(
            'https://test-epay.epayment.kz/payform/payment-api.js',
            $this->gateway->widgetScript()
        );
    }

    public function test_возврат_без_id_операции_не_ходит_в_банк(): void
    {
        Http::fake();

        $payment = $this->payment();
        $payment->update(['external_id' => null]);

        $this->assertFalse($this->gateway->refund($payment));
        Http::assertNothingSent();
    }

    public function test_возврат_отправляет_сумму_в_операцию_банка(): void
    {
        Http::fake([
            '*/oauth2/token' => Http::response(['access_token' => 'tok']),
            '*/operation/*/refund' => Http::response(['status' => 'ok']),
        ]);

        $payment = $this->payment(27000);
        $payment->update(['external_id' => 'trx-9']);

        $this->assertTrue($this->gateway->refund($payment));

        Http::assertSent(function ($request) use ($payment) {
            if (! str_contains($request->url(), '/operation/trx-9/refund')) {
                return false;
            }

            $data = $request->data();

            return ($data['amount'] ?? null) === 27000
                && ($data['externalID'] ?? null) === (string) $payment->id;
        });
    }
}
