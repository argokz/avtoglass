<?php

namespace App\Actions;

use App\Domain\Ordering\PurchaseLifecycle;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Jobs\IssueReceipt;
use App\Models\Assignment;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Приём наличных курьером, мастером или менеджером.
 *
 * Отличается от онлайн-оплаты тем, что деньги принимает человек и какое-то
 * время держит их у себя. Отсюда два правила, которых нет у карты:
 *
 *  1. Записывается, КТО принял. Иначе при расхождении в кассе выяснять нечего.
 *  2. Нельзя принять больше остатка по заказу. Переплата — это либо ошибка
 *     ввода, либо попытка провести через заказ чужие деньги; и то и другое
 *     лучше остановить на месте, чем разбирать в конце месяца.
 */
class AcceptCashPayment
{
    public function handle(
        Order $order,
        float $amount,
        User $receivedBy,
        ?Assignment $assignment = null,
    ): ?Payment {
        $amount = round($amount, 2);

        /*
         * Остаток считается и проверяется под блокировкой заказа.
         *
         * Раньше проверка стояла до транзакции, и два исполнителя — курьер
         * на адресе и менеджер в панели — могли принять один и тот же остаток
         * одновременно: оба видели «к оплате 25 000», оба записали по 25 000,
         * и в кассе оказалось вдвое больше, чем в заказе.
         */
        return DB::transaction(function () use ($order, $amount, $receivedBy, $assignment) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $this->canAccept($locked, $amount)) {
                return null;
            }

            $order = $locked;

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => 'cash',
                'status' => PaymentStatus::Succeeded,
                'amount' => $amount,
                'currency' => $order->currency ?: 'KZT',
                'received_by' => $receivedBy->id,
                'assignment_id' => $assignment?->id,
                'paid_at' => now(),
            ]);

            Interaction::system(
                $order,
                'Принята оплата наличными: '.number_format($amount, 0, ',', ' ').' ₸, принял '.$receivedBy->name
            );

            // Заказ подтверждаем только когда закрыт весь остаток: частичная
            // оплата — это ещё не сделка, и двигать по ней воронку нельзя.
            if ($order->fresh()->outstanding() <= 0
                && in_array($order->status, [OrderStatus::New, OrderStatus::InProgress], true)) {
                $order->transitionTo(OrderStatus::Confirmed);
            }

            app(PurchaseLifecycle::class)->paid($order);
            $this->scheduleReceipt($payment);

            return $payment;
        });
    }

    /** Сколько ещё можно принять по этому заказу. */
    public function canAccept(Order $order, float $amount): bool
    {
        if ($order->purchase_draft_id && ($order->purchase_state !== 'confirmed' || $order->payment_method?->value === 'invoice'
            || abs($amount - $order->outstanding()) > 0.000001)) {
            return false;
        }
        if ($amount <= 0) {
            return false;
        }

        if ($order->status === OrderStatus::Cancelled) {
            return false;
        }

        // Копейка допуска: суммы приходят из формы и могут разойтись
        // с расчётной в последнем знаке.
        return $amount <= $order->outstanding() + 0.01;
    }

    /**
     * Наличные тоже фискализируются, но типом «наличные», а не «карта» —
     * иначе в отчётах ОФД вся выручка будет выглядеть безналичной.
     */
    private function scheduleReceipt(Payment $payment): void
    {
        if (! settings()->enabled('fiscal') || $payment->receipt()->exists()) {
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
