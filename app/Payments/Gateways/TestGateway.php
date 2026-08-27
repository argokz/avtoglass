<?php

namespace App\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\CallbackResult;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Заглушка платёжной системы.
 *
 * Ведёт клиента ровно по тому же пути, что и настоящая касса: уход на внешнюю
 * страницу, выбор исхода, подписанное уведомление, возврат в магазин.
 *
 * Подпись здесь настоящая, хотя проверять на заглушке вроде бы нечего. Так и
 * задумано: если тесты гоняют путь без подписи, то в день подключения боевой
 * кассы обнаружится, что проверять её негде и нечем.
 */
class TestGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'test';
    }

    public function redirectUrl(Payment $payment): ?string
    {
        return route('payment.mock', $payment);
    }

    public function parseCallback(Request $request): ?CallbackResult
    {
        $data = [
            'token' => (string) $request->input('token'),
            'status' => (string) $request->input('status'),
            'amount' => (string) $request->input('amount'),
            'external_id' => (string) $request->input('external_id'),
        ];

        if (! hash_equals($this->sign($data), (string) $request->input('signature'))) {
            return null;
        }

        $status = match ($data['status']) {
            'success' => PaymentStatus::Succeeded,
            'cancel' => PaymentStatus::Cancelled,
            default => PaymentStatus::Failed,
        };

        return new CallbackResult(
            token: $data['token'],
            status: $status,
            amount: (float) $data['amount'],
            externalId: $data['external_id'] ?: null,
            failureReason: $status === PaymentStatus::Failed
                ? (string) ($request->input('reason') ?: 'Банк отклонил операцию')
                : null,
            raw: $request->all(),
        );
    }

    /**
     * Подпись считается по отсортированным полям — как у большинства касс.
     * Порядок параметров в запросе не должен на неё влиять.
     *
     * @param  array<string,string>  $data
     */
    public function sign(array $data): string
    {
        ksort($data);

        return hash_hmac('sha256', implode('|', $data), (string) config('payments.secret'));
    }
}
