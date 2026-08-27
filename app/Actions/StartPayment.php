<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGateway;

/**
 * Создание попытки оплаты.
 *
 * Сумма фиксируется здесь и больше не пересчитывается: прайсы переливаются
 * еженедельно, и клиент должен заплатить ровно ту сумму, которую видел,
 * а не ту, что окажется в базе к моменту нажатия кнопки.
 */
class StartPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(Order $order): ?Payment
    {
        if (! $this->isPayable($order)) {
            return null;
        }

        // Незакрытую попытку переиспользуем: клиент мог вернуться назад
        // и нажать «оплатить» второй раз — плодить платежи из-за этого не нужно.
        $pending = $order->payments()->pending()->latest('id')->first();

        if ($pending !== null && $pending->isPayable() && (float) $pending->amount === (float) $order->total_amount) {
            return $pending;
        }

        return $order->payments()->create([
            'provider' => $this->gateway->name(),
            'status' => PaymentStatus::Pending,
            'amount' => $order->total_amount,
            'currency' => $order->currency ?: 'KZT',
        ]);
    }

    public function redirectUrl(Payment $payment): ?string
    {
        return $this->gateway->redirectUrl($payment);
    }

    /** Платить можно за живой заказ с посчитанной суммой и без уже прошедшей оплаты. */
    public function isPayable(Order $order): bool
    {
        if ($order->status->isFinal()) {
            return false;
        }

        if ($order->total_amount === null || (float) $order->total_amount <= 0) {
            return false;
        }

        return ! $order->isPaid();
    }
}
