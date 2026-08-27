<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;

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
        /*
         * Заказ блокируется на всё время проверки и создания.
         *
         * Без этого два одновременных нажатия «оплатить» — двойной клик,
         * две вкладки, повторная отправка формы — оба не находят незакрытой
         * попытки и оба заводят свою. Дальше каждый колбэк блокирует только
         * свой платёж, оба проходят проверку и оба применяются: клиент
         * заплатил дважды за один заказ.
         *
         * Блокировка именно на заказе, а не на платеже: платежа, из-за
         * которого всё и ломается, в этот момент ещё не существует.
         */
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $this->isPayable($locked)) {
                return null;
            }

            // Незакрытую попытку переиспользуем: клиент мог вернуться назад
            // и нажать «оплатить» второй раз — плодить платежи из-за этого не нужно.
            $pending = $locked->payments()->pending()->latest('id')->first();

            if ($pending !== null && $pending->isPayable()
                && (float) $pending->amount === (float) $locked->total_amount) {
                return $pending;
            }

            return $locked->payments()->create([
                'provider' => $this->gateway->name(),
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total_amount,
                'currency' => $locked->currency ?: 'KZT',
            ]);
        });
    }

    public function redirectUrl(Payment $payment): ?string
    {
        return $this->gateway->redirectUrl($payment);
    }

    /** Платить можно за живой заказ с посчитанной суммой и без уже прошедшей оплаты. */
    public function isPayable(Order $order): bool
    {
        if (! settings()->enabled('payments')) {
            return false;
        }

        if ($order->status->isFinal()) {
            return false;
        }

        if ($order->total_amount === null || (float) $order->total_amount <= 0) {
            return false;
        }

        return ! $order->isPaid();
    }
}
