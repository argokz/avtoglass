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
     * @return array{token:string,invoice_id:string,secret_hash:string,oauth:array}|null
     */
    public function authorize(Payment $payment): ?array
    {
        $invoiceId = $this->mintInvoiceId($payment);
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

        $oauth = $response->json();

        return [
            'token' => (string) $response->json('access_token'),
            'oauth' => is_array($oauth) ? $oauth : ['access_token' => (string) $response->json('access_token')],
            'invoice_id' => $invoiceId,
            'secret_hash' => $secretHash,
        ];
    }

    /** Поля, которые уходят в halyk.pay() на нашей странице. */
    public function widgetPayload(Payment $payment, array $auth): array
    {
        $payload = [
            'invoiceId' => $auth['invoice_id'],
            'backLink' => route('payment.result', $payment),
            'failureBackLink' => route('payment.result', $payment),
            'autoBackLink' => true,
            'postLink' => route('payment.halyk.callback'),
            'language' => 'rus',
            'description' => Str::limit('Заказ '.$payment->order->number, 120, ''),
            'terminal' => config('payments.halyk.terminal'),
            'amount' => (int) round((float) $payment->amount),
            'currency' => $payment->currency,
            // Банк ждёт ответ oauth целиком, не одну строку access_token:
            // иначе страница кассы сразу пишет «время оплаты истекло».
            'auth' => $auth['oauth'] ?? $auth['token'],
        ];

        $phone = preg_replace('/\D+/', '', (string) $payment->order->customer?->phone);

        if (filled($phone)) {
            $payload['phone'] = $phone;
        }

        return $payload;
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

        $payment = $this->findByInvoiceId($invoiceId);

        if ($payment === null) {
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
     * Номер счёта, который ушёл в банк по этому платежу.
     *
     * Банк требует 6–15 цифр и уникальность последних шести на терминале.
     * Песочница общая на всех, поэтому короткий id платежа (000076) там
     * уже кто-то использовал — касса сразу отвечает «время оплаты истекло».
     */
    public function invoiceId(Payment $payment): string
    {
        $stored = $payment->payload['invoice_id'] ?? null;

        if (is_string($stored) && preg_match('/^\d{6,15}$/', $stored)) {
            return $stored;
        }

        return str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Новый номер на каждый запрос токена: повтор той же попытки не должен
     * упираться в уже закрытый счёт в банке.
     */
    private function mintInvoiceId(Payment $payment): string
    {
        $id = sprintf('%09d%06d', now()->timestamp % 1_000_000_000, random_int(0, 999_999));

        $payload = $payment->payload ?? [];
        $payload['invoice_id'] = $id;
        $payment->forceFill(['payload' => $payload])->save();

        return $id;
    }

    private function findByInvoiceId(string $invoiceId): ?Payment
    {
        $match = Payment::query()
            ->where('provider', $this->name())
            ->where('payload->invoice_id', $invoiceId)
            ->first();

        if ($match !== null) {
            return $match;
        }

        // Старые попытки, пока номер счёта ещё равнялся id платежа.
        if (! preg_match('/^\d{6}$/', $invoiceId)) {
            return null;
        }

        $payment = Payment::query()
            ->where('provider', $this->name())
            ->whereKey((int) $invoiceId)
            ->first();

        if ($payment === null || filled($payment->payload['invoice_id'] ?? null)) {
            return null;
        }

        return $payment;
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

    public function refund(Payment $payment): bool
    {
        $transactionId = $payment->external_id;

        if (! filled($transactionId)) {
            Log::warning('Halyk: возврат без id операции банка', ['payment_id' => $payment->id]);

            return false;
        }

        $token = $this->clientAccessToken();

        if ($token === null) {
            return false;
        }

        $url = rtrim($this->endpoint('api'), '/').'/operation/'.rawurlencode((string) $transactionId).'/refund';

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post($url, [
                    'amount' => (int) round((float) $payment->amount),
                    'externalID' => (string) $payment->id,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Halyk ePay недоступен при возврате', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Halyk отказал в возврате', [
                'payment_id' => $payment->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    private function clientAccessToken(): ?string
    {
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post($this->endpoint('oauth'), [
                    'grant_type' => 'client_credentials',
                    'scope' => 'webapi usermanagement email_send verification statement statistics payment',
                    'client_id' => config('payments.halyk.client_id'),
                    'client_secret' => config('payments.halyk.client_secret'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Halyk ePay недоступен', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            return null;
        }

        return (string) $response->json('access_token');
    }

    private function endpoint(string $key): string
    {
        $mode = config('payments.halyk.test_mode') ? 'test' : 'live';

        return (string) config("payments.halyk.endpoints.{$mode}.{$key}");
    }
}
