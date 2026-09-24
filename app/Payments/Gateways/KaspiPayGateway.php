<?php

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\CallbackResult;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Шлюз Kaspi Pay / Kaspi QR.
 *
 * Позволяет выставить счет на оплату в приложение Kaspi.kz по номеру телефона,
 * сформировать QR-код на оплату или получить платежную ссылку.
 */
class KaspiPayGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'kaspi';
    }

    public function redirectUrl(Payment $payment): ?string
    {
        $testMode = (bool) config('payments.kaspi.test_mode', true);
        $merchantId = (string) config('payments.kaspi.merchant_id', 'test_merchant');
        $apiKey = (string) config('payments.kaspi.api_key', 'test_key');

        if ($testMode) {
            // В тестовом режиме перенаправляем на эмуляцию Kaspi QR с возможностью оплатить
            return route('payment.mock', [
                'payment' => $payment,
                'gateway' => 'kaspi',
                'title' => 'Оплата через Kaspi QR',
            ]);
        }

        try {
            $response = Http::withHeaders([
                'X-Kaspi-ApiKey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post(config('payments.kaspi.api_url', 'https://api.kaspi.kz/pay/v1/orders'), [
                'merchant_id' => $merchantId,
                'order_id' => (string) $payment->id,
                'amount' => (float) $payment->amount,
                'currency' => 'KZT',
                'description' => "Заказ {$payment->order->number}",
                'callback_url' => route('payment.callback', ['gateway' => 'kaspi']),
                'return_url' => route('payment.result', $payment),
                'customer_phone' => $payment->order->customer?->phone,
            ]);

            if ($response->successful() && $url = $response->json('payment_url')) {
                return $url;
            }

            Log::error('Kaspi Pay API error', ['status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $e) {
            Log::error('Kaspi Pay connection failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function parseCallback(Request $request): ?CallbackResult
    {
        $paymentId = (int) $request->input('order_id', $request->input('payment_id'));
        $payment = Payment::find($paymentId);

        if ($payment === null) {
            return null;
        }

        $statusStr = mb_strtolower((string) $request->input('status', ''));
        $status = match ($statusStr) {
            'success', 'paid', 'approved' => PaymentStatus::Succeeded,
            'failed', 'declined', 'cancelled' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        return new CallbackResult(
            token: $payment->token,
            status: $status,
            amount: (float) $payment->amount,
            externalId: (string) $request->input('transaction_id', $request->input('id')),
            raw: $request->all(),
        );
    }

    public function refund(Payment $payment): bool
    {
        if (config('payments.kaspi.test_mode')) {
            return true;
        }

        $url = (string) config('payments.kaspi.refund_url');

        if ($url === '' || ! filled($payment->external_id)) {
            Log::warning('Kaspi: возврат без URL или id операции', ['payment_id' => $payment->id]);

            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Kaspi-ApiKey' => (string) config('payments.kaspi.api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(20)->post($url, [
                'merchant_id' => config('payments.kaspi.merchant_id'),
                'order_id' => (string) $payment->id,
                'transaction_id' => $payment->external_id,
                'amount' => (float) $payment->amount,
            ]);
        } catch (\Throwable $e) {
            Log::error('Kaspi Pay refund failed', ['error' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }
}
