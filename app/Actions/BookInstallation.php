<?php

namespace App\Actions;

use App\Enums\AssignmentType;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Logistics\BookingSlots;
use App\Logistics\Scheduler;
use App\Logistics\SlotTaken;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Запись на замену: заявка и наряд одним действием.
 *
 * Отличается от обычной заявки тем, что клиент называет время сам, и это
 * время нужно за ним закрепить. Отсюда главное правило: слот проверяется
 * второй раз, уже внутри транзакции. Между тем, как человек увидел окна,
 * и тем, как он нажал кнопку, проходят минуты — за них пост успевает занять
 * кто-то другой, и записать двоих на один подъёмник значит испортить день
 * обоим.
 */
class BookInstallation
{
    public function __construct(
        private readonly CreateOrder $orders,
        private readonly Scheduler $scheduler,
        private readonly BookingSlots $slots,
    ) {}

    /**
     * @param  array{name:string,phone:string,comment?:?string,car_info?:?string}  $data
     * @return Order|null null означает «время уже заняли», а не ошибку
     */
    public function handle(
        array $data,
        ServicePoint $point,
        Carbon $start,
        ?Product $product = null,
    ): ?Order {
        // Быстрая отбраковка до транзакции: горизонт и запас на подготовку
        if (! $this->slots->allows($start)) {
            return null;
        }

        $duration = $this->slots->duration(Service::defaultFor($product?->glass_type));

        try {
            return DB::transaction(
                fn () => $this->book($data, $point, $start, $product, $duration)
            );
        } catch (SlotTaken) {
            // Транзакция уже откачена: заявки без времени не осталось.
            return null;
        }
    }

    private function book(
        array $data,
        ServicePoint $point,
        Carbon $start,
        ?Product $product,
        int $duration,
    ): Order {
        $order = $this->orders->handle(
            [
                ...$data,
                'fulfillment' => FulfillmentType::Installation->value,
                'city' => $point->city ?: currentCity(),
            ],
            OrderSource::Booking,
            $product,
        );

        $assignment = $this->scheduler->schedule(
            order: $order,
            type: AssignmentType::Installation,
            start: $start,
            point: $point,
            duration: $duration,
        );

        if ($assignment === null) {
            /*
             * Пост заняли, пока человек заполнял форму. Исключением откатываем
             * и заявку: запись без времени хуже, чем честное «выберите другое»,
             * — клиент считает, что записан, и приезжает к занятому подъёмнику.
             */
            throw new SlotTaken;
        }

        $order->update(['scheduled_at' => $start]);
        $order->transitionTo(OrderStatus::Scheduled);

        Interaction::system(
            $order,
            'Клиент записался сам: '.$start->format('d.m.Y H:i').', '.$point->name
        );

        $order->tasks()->latest('id')->first()?->update([
            'description' => 'Клиент записался на '.$start->format('d.m.Y H:i').', '.$point->name
                .'. Подтвердить наличие стекла.',
        ]);

        return $order->fresh();
    }
}
