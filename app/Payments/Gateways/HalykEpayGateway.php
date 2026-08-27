<?php

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\CallbackResult;
use App\Payments\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Halyk Bank ePay 2.0.
 *
 * Отличается от привычной схемы «редирект на домен банка»: ePay — это
 * JS-виджет, который поднимается на нашей странице. Поэтому redirectUrl()
 * ведёт на наш же маршрут, а тот уже получает токен и запускает виджет.
 * Контракт от этого не страдает — снаружи всё выглядит так же.
 *
 * Подлинность уведомления проверяется по secret_hash: мы придумываем его при
 * запросе токена, банк возвращает его в postLink. Ничего больше в колбэке
 * подписью не защищено, поэтому сверять его обязательно.
 */
class HalykEpayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'halyk';
    }

    public function redirectUrl(Payment $payment): ?string
    {
        return route('payment.halyk', $payment);
    }

    /**
     * Токен под конкретный платёж. Банк привязывает его к сумме и номеру
     * счёта, поэтому получать его заранее и переиспользовать нельзя.
     *
     * @return array{token:string,invoice_id:string,secret_hash:string}|null
     */
    public function authorize(Payment $payment): ?array
    {
        $invoiceId = $this->invoiceId($payment);
        $secretHash = $this->secretHash($payment);

        /*
         * Сеть до банка — самое ненадёжное звено в оплате, и падать из-за неё
         * нельзя. Ответ 5xx мы разбираем ниже, но настоящий обрыв — таймаут,
         * недоступный DNS, отвалившийся TLS — приходит исключением, и без
         * этого перехвата клиент вместо «касса недоступна» видит пятисотую.
         */
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post($this->endpoint('oauth'), [
                    'grant_type' => 'client_credentials',
                    'scope' => 'webapi usermanagement email_send verification statement statistics payment',
                    'client_id' => config('payments.halyk.client_id'),
                    'client_secret' => config('payments.halyk.client_secret'),
                    'invoiceID' => $invoiceId,
                    'amount' => (int) round((float) $payment->amount),
                    'currency' => $payment->currency,
                    'terminal' => config('payments.halyk.terminal'),
                    'postLink' => route('payment.halyk.callback'),
                    'failurePostLink' => route('payment.halyk.callback'),
                    'secret_hash' => $secretHash,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Halyk ePay недоступен', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            return null;
        }

        return [
            'token' => (string) $response->json('access_token'),
            'invoice_id' => $invoiceId,
            'secret_hash' => $secretHash,
        ];
    }

    /** Поля, которые уходят в halyk.pay() на нашей странице. */
    public function widgetPayload(Payment $payment, array $auth): array
    {
        return [
            'invoiceId' => $auth['invoice_id'],
            'backLink' => route('payment.result', $payment),
            'failureBackLink' => route('payment.result', $payment),
            'postLink' => route('payment.halyk.callback'),
            'language' => 'rus',
            'description' => Str::limit('Заказ '.$payment->order->number, 120, ''),
            'terminal' => config('payments.halyk.terminal'),
            'amount' => (int) round((float) $payment->amount),
            'currency' => $payment->currency,
            'auth' => $auth['token'],
        ];
    }

    public function widgetScript(): string
    {
        return $this->endpoint('widget');
    }

    /**
     * postLink приходит от банка сервером на сервер, в теле JSON.
     * Своей подписи у него нет — сверяем secret_hash, который сами и задали.
     */
    public function parseCallback(Request $request): ?CallbackResult
    {
        $invoiceId = (string) $request->input('invoiceId', $request->input('invoiceID'));

        if ($invoiceId === '') {
            return null;
        }

        // Номер счёта — это id платежа с ведущими нулями, поэтому платёж
        // достаётся одним запросом по ключу. Перебирать все платежи провайдера
        // в памяти нельзя: на второй тысяче колбэк начнёт таймаутить.
        $payment = Payment::where('provider', $this->name())
            ->whereKey((int) $invoiceId)
            ->first();

        if ($payment === null || $this->invoiceId($payment) !== $invoiceId) {
            return null;
        }

        $hash = (string) $request->input('secret_hash');

        if (! hash_equals($this->secretHash($payment), $hash)) {
            return null;
        }

        $code = (string) $request->input('code');

        return new CallbackResult(
            token: $payment->token,
            status: $code === 'ok' ? PaymentStatus::Succeeded : PaymentStatus::Failed,
            // Сумму берём из уведомления, чтобы её сверил ApplyPaymentCallback:
            // подставлять сюда свою значило бы отключить эту проверку.
            amount: (float) $request->input('amount', 0),
            externalId: $request->input('id'),
            failureReason: $code === 'ok' ? null : (string) ($request->input('reason') ?: 'Банк отклонил операцию'),
            raw: $request->all(),
        );
    }

    /**
     * Номер счёта для банка: 6–15 цифр, уникальный на заказ.
     * Берём id платежа — он и уникален, и находится по нему обратно.
     */
    public function invoiceId(Payment $payment): string
    {
        return str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Секрет, привязанный к платежу. Выводим детерминированно из токена
     * платежа и ключа приложения: хранить отдельное поле незачем,
     * а угадать снаружи невозможно.
     */
    private function secretHash(Payment $payment): string
    {
        return hash_hmac('sha256', $payment->token, (string) config('app.key'));
    }

    private function endpoint(string $key): string
    {
        $mode = config('payments.halyk.test_mode') ? 'test' : 'live';

        return (string) config("payments.halyk.endpoints.{$mode}.{$key}");
    }
}
