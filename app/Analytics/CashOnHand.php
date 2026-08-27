<?php

namespace App\Analytics;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Сколько наличных у кого на руках.
 *
 * Приём денег от клиента был закрыт: известно, кто взял и сколько. Но между
 * «курьер взял 25 000 на адресе» и «деньги лежат в кассе» стоит поездка,
 * и до сих пор она нигде не отражалась — settled_at существовал, но его
 * никто не заполнял. То есть система знала, что деньги приняты, и не знала,
 * дошли ли они.
 *
 * Это единственное место в денежном контуре, где нет второй подписи, а именно
 * здесь наличные и пропадают.
 */
class CashOnHand
{
    /**
     * У кого сколько несданного, по убыванию суммы.
     *
     * @return Collection<int,object{user:User,total:float,count:int,oldest:?Carbon}>
     */
    public function byPerson(): Collection
    {
        return Payment::query()
            ->cashOnHand()
            ->with('receiver')
            ->get()
            ->groupBy('received_by')
            ->map(fn (Collection $payments) => (object) [
                'user' => $payments->first()->receiver,
                'total' => (float) $payments->sum('amount'),
                'count' => $payments->count(),
                // Самый старый платёж — то, на что смотрят в первую очередь:
                // деньги, которые везут третий день, это уже не поездка.
                'oldest' => $payments->min('paid_at'),
            ])
            ->filter(fn (object $row) => $row->user !== null)
            ->sortByDesc('total')
            ->values();
    }

    /** @return Collection<int,Payment> что именно держит этот человек */
    public function forPerson(User $user): Collection
    {
        return Payment::query()
            ->cashOnHand()
            ->where('received_by', $user->getKey())
            ->with('order.customer')
            ->orderBy('paid_at')
            ->get();
    }

    public function totalFor(User $user): float
    {
        return (float) Payment::query()
            ->cashOnHand()
            ->where('received_by', $user->getKey())
            ->sum('amount');
    }

    /** Вся несданная наличность — цифра для дашборда. */
    public function total(): float
    {
        return (float) Payment::query()->cashOnHand()->sum('amount');
    }
}
