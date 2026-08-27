<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Jobs\IssueReceipt;
use App\Models\Interaction;
use App\Models\Payment;
use App\Models\Receipt;
use App\Payments\CallbackResult;
use Illuminate\Support\Facades\DB;

/**
 * Применение уведомления кассы. Самое ответственное место в оплате.
 *
 * Четыре правила, каждое из которых защищает от реальной беды:
 *
 *  1. Строка платежа блокируется на время проверки. Кассы шлют колбэк по
 *     нескольку раз и не ждут, пока предыдущий обработается: два уведомления
 *     могут войти сюда одновременно, оба увидеть «ещё не применён» и оба
 *     подтвердить заказ. Блокировка делает проверку и запись неразрывными.
 *  2. Повторное уведомление игнорируется — по статусу, прочитанному уже
 *     под блокировкой.
 *  3. Сумма сверяется с той, что мы выставили. Уведомление приходит извне,
 *     и доверять сумме из него нельзя.
 *  4. Всё пишется в одной транзакции: платёж, заказ и журнал должны меняться
 *     вместе или не меняться вовсе.
 */
class ApplyPaymentCallback
{
    public function handle(Payment $payment, CallbackResult $result): bool
    {
        return DB::transaction(function () use ($payment, $result) {
            // Читаем заново под блокировкой: значения, с которыми пришли,
            // могли устареть между HTTP-запросом и этой строкой.
            $payment = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->first();

            if ($payment === null || $payment->status->isFinal()) {
                return false;   // уже применяли
            }

            if (abs((float) $payment->amount - $result->amount) > 0.01) {
                $payment->update([
                    'status' => PaymentStatus::Failed,
                    'failure_reason' => 'Сумма в уведомлении не совпала с выставленной',
                    'payload' => $result->raw,
                ]);

                return false;
            }

            // Промежуточное уведомление: клиент ещё на странице банка.
            // Платёж не тронут — иначе следующий, окончательный колбэк
            // упрётся в «уже применяли» и оплата потеряется.
            if (! $result->status->isFinal()) {
                $payment->update(['payload' => $result->raw]);

                return true;
            }

            $payment->update([
                'status' => $result->status,
                'external_id' => $result->externalId,
                'failure_reason' => $result->failureReason,
                'payload' => $result->raw,
                'paid_at' => $result->status->isPaid() ? now() : null,
            ]);

            $order = $payment->order;

            if ($result->status->isPaid()) {
                Interaction::system(
                    $order,
                    'Оплата получена: '.number_format((float) $payment->amount, 0, ',', ' ').' ₸'
                );

                // Оплаченный заказ подтверждён по определению — клиент уже
                // проголосовал деньгами, ждать подтверждения менеджера незачем.
                if ($order->status === OrderStatus::New || $order->status === OrderStatus::InProgress) {
                    $order->transitionTo(OrderStatus::Confirmed);
                }

                $this->scheduleReceipt($payment);
            } else {
                Interaction::system($order, 'Оплата не прошла: '.$result->status->label());
            }

            return true;
        });
    }

    /**
     * Ставит чек в очередь на фискализацию.
     *
     * Именно в очередь, а не сразу: касса ОФД падает чаще банка, и делать
     * подтверждение оплаты зависимым от неё значит превращать каждый её сбой
     * в «клиент заплатил, а заказ не подтвердился».
     */
    private function scheduleReceipt(Payment $payment): void
    {
        if (! settings()->enabled('fiscal')) {
            return;
        }

        // Повторный колбэк сюда не дойдёт, но подстрахуемся: два чека
        // на одну оплату — это уже разговор с налоговой.
        if ($payment->receipt()->exists()) {
            return;
        }

        $receipt = Receipt::create([
            'order_id' => $payment->order_id,
            'payment_id' => $payment->id,
            'status' => ReceiptStatus::Pending,
            'amount' => $payment->amount,
        ]);

        /*
         * afterCommit обязателен: чек ставится в очередь внутри транзакции,
         * и без этого воркер успевает схватить задачу раньше, чем платёж
         * окажется в базе. Тогда он не находит ни чека, ни оплаты и тихо
         * выходит — а клиент остаётся без фискального документа.
         */
        IssueReceipt::dispatch($receipt)->afterCommit();
    }
}
