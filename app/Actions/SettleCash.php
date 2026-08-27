<?php

namespace App\Actions;

use App\Analytics\CashOnHand;
use App\Models\Interaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Приём наличных в кассу.
 *
 * Вторая подпись под теми же деньгами. Приём от клиента уже фиксирует, кто
 * взял; здесь фиксируется, кто принял и когда. Без этого шага система знала,
 * что деньги получены, но не знала, дошли ли они до офиса, — а пропадают
 * наличные именно на этом отрезке.
 *
 * Сдаёт не курьер, а принимает кассир: отметить свои же деньги сданными
 * человек не может, иначе вторая подпись ничего не значит.
 */
class SettleCash
{
    public function __construct(private readonly CashOnHand $cash) {}

    /**
     * Принять всё, что числится за человеком.
     *
     * Сумму передаём отдельно и сверяем: кассир пересчитывает деньги в руках,
     * и если получилось не столько, принимать нельзя — это тот самый момент,
     * когда расхождение ещё можно разобрать, а не искать его в конце месяца.
     *
     * @return int сколько платежей закрыто, 0 — если сумма не сошлась
     */
    public function handle(User $from, User $cashier, ?float $countedAmount = null): int
    {
        return DB::transaction(function () use ($from, $cashier, $countedAmount) {
            $payments = Payment::query()
                ->cashOnHand()
                ->where('received_by', $from->getKey())
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                return 0;
            }

            $expected = round((float) $payments->sum('amount'), 2);

            // Копейка допуска: сумма приходит из формы
            if ($countedAmount !== null && abs($countedAmount - $expected) > 0.01) {
                return 0;
            }

            Payment::whereIn('id', $payments->pluck('id'))->update([
                'settled_at' => now(),
                'settled_by' => $cashier->getKey(),
                'updated_at' => now(),
            ]);

            $this->logToOrders($payments, $from, $cashier);

            return $payments->count();
        });
    }

    /**
     * Запись в журнал каждой заявки.
     *
     * Спрашивать «где деньги за этот заказ» будут, глядя в заказ, а не
     * в сводку по кассе, — значит и ответ должен лежать там же.
     */
    private function logToOrders($payments, User $from, User $cashier): void
    {
        foreach ($payments as $payment) {
            if ($payment->order === null) {
                continue;
            }

            Interaction::system(
                $payment->order,
                'Наличные сданы в кассу: '.number_format((float) $payment->amount, 0, ',', ' ')
                .' ₸, сдал '.$from->name.', принял '.$cashier->name
            );
        }
    }
}
