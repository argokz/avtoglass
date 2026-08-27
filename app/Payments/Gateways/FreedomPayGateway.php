<?php

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\CallbackResult;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Freedom Pay (бывший PayBox).
 *
 * Схема подписи у них своя и неочевидная: MD5 от строки, склеенной через «;»
 * из имени скрипта, ВСЕХ полей запроса в алфавитном порядке по ключу и секрета
 * мерчанта. Имя скрипта участвует обязательно — из-за этого подпись запроса
 * и подпись ответа на колбэк считаются с разными первыми элементами,
 * и перепутать их проще всего.
 *
 * Отдельная тонкость: в подпись входят вообще все переданные поля, включая те,
 * которых нет в документации. Добавить параметр и забыть про подпись — значит
 * получить отказ, который выглядит как «неверный секретный ключ».
 */
class FreedomPayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'freedompay';
    }

    public function redirectUrl(Payment $payment): ?string
    {
        $params = [
            'pg_order_id' => (string) $payment->id,
            'pg_merchant_id' => (string) config('payments.freedompay.merchant_id'),
            'pg_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'pg_currency' => $payment->currency,
            'pg_description' => Str::limit('Заказ '.$payment->order->number, 120, ''),
            'pg_salt' => Str::random(16),
            'pg_result_url' => route('payment.freedompay.callback'),
            'pg_success_url' => route('payment.result', $payment),
            'pg_failure_url' => route('payment.result', $payment),
            'pg_request_method' => 'POST',
        ];

        if (config('payments.freedompay.test_mode')) {
            $params['pg_testing_mode'] = '1';
        }

        $params['pg_sig'] = $this->sign('init_payment.php', $params);

        $response = Http::asForm()->timeout(20)->post(config('payments.freedompay.init_url'), $params);

        if (! $response->successful()) {
            return null;
        }

        $xml = $this->parseXml($response->body());

        if (($xml['pg_status'] ?? '') !== 'ok' || empty($xml['pg_redirect_url'])) {
            // Причину пишем в лог: снаружи она выглядит как «касса недоступна»,
            // а разбираться потом придётся именно по ней.
            Log::warning('FreedomPay отказал в инициализации платежа', [
                'payment_id' => $payment->id,
                'error' => $xml['pg_error_description'] ?? $response->body(),
            ]);

            return null;
        }

        // Идентификатор на стороне банка нужен, чтобы потом сверить колбэк
        $payment->update(['external_id' => $xml['pg_payment_id'] ?? null]);

        return (string) $xml['pg_redirect_url'];
    }

    public function parseCallback(Request $request): ?CallbackResult
    {
        $data = $request->all();
        $signature = (string) ($data['pg_sig'] ?? '');

        // Имя скрипта для подписи — последний сегмент нашего же result_url:
        // банк подписывает тем адресом, по которому стучится.
        $script = $this->scriptName(route('payment.freedompay.callback'));

        if (! hash_equals($this->sign($script, $data), $signature)) {
            return null;
        }

        $payment = Payment::where('provider', $this->name())
            ->whereKey((int) ($data['pg_order_id'] ?? 0))
            ->first();

        if ($payment === null) {
            return null;
        }

        $status = match ((string) ($data['pg_result'] ?? '')) {
            '1' => PaymentStatus::Succeeded,
            '2' => PaymentStatus::Pending,   // клиент ещё не закончил
            default => PaymentStatus::Failed,
        };

        return new CallbackResult(
            token: $payment->token,
            status: $status,
            amount: (float) ($data['pg_amount'] ?? 0),
            externalId: $data['pg_payment_id'] ?? null,
            failureReason: $status === PaymentStatus::Failed
                ? (string) ($data['pg_failure_description'] ?? 'Платёж не прошёл')
                : null,
            raw: $data,
        );
    }

    /**
     * Ответ банку. Тоже подписанный XML — без него Freedom Pay считает,
     * что магазин уведомление не принял, и шлёт его снова.
     */
    public function callbackResponse(string $status = 'ok'): string
    {
        $script = $this->scriptName(route('payment.freedompay.callback'));

        $body = [
            'pg_status' => $status,
            'pg_salt' => Str::random(16),
        ];

        $body['pg_sig'] = $this->sign($script, $body);

        $xml = new \SimpleXMLElement('<response/>');
        foreach ($body as $key => $value) {
            $xml->addChild($key, htmlspecialchars((string) $value));
        }

        return $xml->asXML();
    }

    /**
     * MD5 от «имя_скрипта;значения_полей_по_алфавиту;секрет».
     * pg_sig в подписи не участвует — иначе она зависела бы от самой себя.
     *
     * @param  array<string,mixed>  $params
     */
    public function sign(string $script, array $params): string
    {
        unset($params['pg_sig']);

        ksort($params);

        $parts = array_merge(
            [$script],
            array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), array_values($params)),
            [(string) config('payments.freedompay.secret')]
        );

        return md5(implode(';', $parts));
    }

    /** «https://site.kz/oplata/freedompay/callback» -> «callback» */
    private function scriptName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return basename($path);
    }

    /** @return array<string,string> */
    private function parseXml(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return [];
        }

        return array_map('strval', (array) $xml);
    }
}
