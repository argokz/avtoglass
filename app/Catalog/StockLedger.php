<?php

namespace App\Catalog;

use App\Enums\ReservationStatus;
use App\Models\Interaction;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Учёт того, что физически лежит на складе и кому оно уже обещано.
 *
 * Свободный остаток — это не stock_qty. Это stock_qty минус то, что отложено
 * под чужие заказы. Пока этой разницы не было, одну и ту же единицу можно было
 * продать онлайн сколько угодно раз, а клиент узнавал об этом от менеджера
 * после оплаты — худший момент из возможных.
 *
 * Три события меняют картину:
 *   подтверждение заказа  -> резерв (стекло больше никому не продаётся)
 *   отмена или протухание -> снятие резерва (вернулось в продажу)
 *   выполнение заказа     -> списание (стекло уехало, остатка стало меньше)
 */
class StockLedger
{
    /** Сколько единиц можно продать прямо сейчас. */
    public function available(Offer $offer): int
    {
        return max(0, (int) $offer->stock_qty - $this->held($offer));
    }

    /** Сколько единиц отложено под чужие заказы. */
    public function held(Offer $offer): int
    {
        return (int) StockReservation::query()
            ->where('offer_id', $offer->getKey())
            ->held()
            ->sum('quantity');
    }

    /**
     * Отложить стекло под заказ.
     *
     * Заказ при нехватке всё равно подтверждается: он мог быть уже оплачен,
     * и «откатить» подтверждение из-за склада значит взять деньги и оставить
     * клиента ни с чем. Вместо этого резервируем сколько есть и пишем в журнал,
     * чтобы менеджер увидел проблему до звонка клиенту, а не после.
     */
    public function reserve(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if ($item->offer_id === null) {
                continue;   // позиция без привязки к поставщику — резервировать нечего
            }

            $this->reserveItem($order, $item);
        }
    }

    private function reserveItem(Order $order, OrderItem $item): void
    {
        DB::transaction(function () use ($order, $item) {
            // Блокируем строку предложения: два одновременных подтверждения
            // иначе оба увидят «одна штука свободна» и оба её займут.
            $offer = Offer::query()->whereKey($item->offer_id)->lockForUpdate()->first();

            if ($offer === null) {
                return;
            }

            if (StockReservation::where('order_item_id', $item->getKey())->exists()) {
                return;   // уже бронировали — повторное подтверждение резерв не удваивает
            }

            $wanted = (int) $item->quantity;
            $granted = min($wanted, $this->available($offer));

            if ($granted <= 0) {
                Interaction::system(
                    $order,
                    'Нет на складе: '.$item->name.' — резерв не поставлен, нужен заказ у поставщика'
                );

                return;
            }

            StockReservation::create([
                'offer_id' => $offer->getKey(),
                'order_id' => $order->getKey(),
                'order_item_id' => $item->getKey(),
                'quantity' => $granted,
                'status' => ReservationStatus::Held,
                'expires_at' => $this->expiryFor($order),
            ]);

            if ($granted < $wanted) {
                Interaction::system(
                    $order,
                    'Зарезервировано частично: '.$item->name.' — '.$granted.' из '.$wanted.' шт'
                );
            }
        });
    }

    /**
     * Оплаченный заказ держит стекло без срока: клиент уже заплатил, и снимать
     * с него бронь по таймеру — значит продать оплаченное второй раз.
     */
    private function expiryFor(Order $order): ?Carbon
    {
        return $order->outstanding() > 0
            ? now()->addDays((int) config('catalog.reservation.unpaid_days'))
            : null;
    }

    /** Вернуть отложенное в продажу. */
    public function release(Order $order, string $reason = 'заказ отменён'): int
    {
        $reservations = StockReservation::where('order_id', $order->getKey())->held()->get();

        if ($reservations->isEmpty()) {
            return 0;
        }

        StockReservation::whereIn('id', $reservations->pluck('id'))
            ->update(['status' => ReservationStatus::Released, 'updated_at' => now()]);

        Interaction::system(
            $order,
            'Резерв снят ('.$reason.'): '.$reservations->sum('quantity').' шт вернулись в продажу'
        );

        return $reservations->count();
    }

    /**
     * Списать отгруженное.
     *
     * Только здесь stock_qty уменьшается — до момента выдачи стекло физически
     * лежит на складе, и остаток обязан это показывать, иначе не сойдётся
     * инвентаризация.
     */
    public function consume(Order $order): int
    {
        $reservations = StockReservation::where('order_id', $order->getKey())->held()->get();

        foreach ($reservations as $reservation) {
            DB::transaction(function () use ($reservation) {
                Offer::whereKey($reservation->offer_id)
                    ->lockForUpdate()
                    ->decrement('stock_qty', $reservation->quantity);

                $reservation->update(['status' => ReservationStatus::Consumed]);
            });
        }

        return $reservations->count();
    }

    /**
     * Снять протухшие брони.
     *
     * Заявка, по которой месяц никто не заплатил, не должна держать стекло:
     * это тихо превращает полный склад в пустой.
     */
    public function releaseExpired(): int
    {
        $released = 0;

        StockReservation::expired()
            ->with('order')
            ->chunkById(200, function ($reservations) use (&$released) {
                foreach ($reservations as $reservation) {
                    $reservation->update(['status' => ReservationStatus::Released]);

                    if ($reservation->order !== null) {
                        Interaction::system(
                            $reservation->order,
                            'Резерв снят по сроку: заказ не оплачен '
                            .config('catalog.reservation.unpaid_days').' дн.'
                        );
                    }

                    $released++;
                }
            });

        return $released;
    }
}
