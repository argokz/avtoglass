<?php

namespace App\Logistics;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Кто, когда и где выполняет работу по заказу.
 *
 * Вся ценность здесь в проверках занятости. Без них двойная запись обнаружится
 * в тот момент, когда два клиента одновременно заедут на один пост, — а это
 * уже не ошибка в базе, а испорченный день у обоих.
 */
class Scheduler
{
    /**
     * Можно ли поставить наряд на это время.
     *
     * @param  ?int  $ignoreId  наряд, который сейчас переносим: сам себе он не помеха
     * @return array<int,ScheduleConflict>
     */
    public function conflicts(
        AssignmentType $type,
        Carbon $start,
        int $duration,
        ?ServicePoint $point = null,
        ?User $assignee = null,
        ?int $ignoreId = null,
    ): array {
        $end = $start->copy()->addMinutes($duration);
        $conflicts = [];

        if ($start->isPast()) {
            $conflicts[] = ScheduleConflict::InThePast;
        }

        if ($point !== null) {
            if (! $this->withinWorkingHours($point, $start, $end)) {
                $conflicts[] = ScheduleConflict::PointClosed;
            }

            if ($this->busyBays($point, $start, $end, $ignoreId) >= $point->bays) {
                $conflicts[] = ScheduleConflict::PointBusy;
            }
        }

        if ($assignee !== null && $this->assigneeBusy($assignee, $start, $end, $ignoreId)) {
            $conflicts[] = ScheduleConflict::AssigneeBusy;
        }

        return $conflicts;
    }

    /**
     * Создаёт наряд. Возвращает null, если время занято — решение,
     * что делать дальше, принимает вызывающий код.
     */
    public function schedule(
        Order $order,
        AssignmentType $type,
        Carbon $start,
        ?ServicePoint $point = null,
        ?User $assignee = null,
        ?int $duration = null,
        ?string $note = null,
    ): ?Assignment {
        $duration ??= $type->defaultDuration();

        if ($this->conflicts($type, $start, $duration, $point, $assignee) !== []) {
            return null;
        }

        return Assignment::create([
            'order_id' => $order->id,
            'type' => $type,
            'status' => $assignee ? AssignmentStatus::Assigned : AssignmentStatus::Planned,
            'assignee_id' => $assignee?->id,
            'service_point_id' => $point?->id,
            'scheduled_at' => $start,
            'duration_minutes' => $duration,
            // Адрес фиксируем снимком: в заказе его потом могут поправить,
            // а курьер должен приехать туда, куда договаривались.
            'address' => $type === AssignmentType::Delivery ? $order->address : null,
            'note' => $note,
        ]);
    }

    /**
     * Свободные начала слотов на день. Шаг в полчаса — так в сервисах
     * и договариваются, «на десять» или «на пол-одиннадцатого».
     *
     * @return array<int,Carbon>
     */
    public function availableSlots(ServicePoint $point, Carbon $day, int $duration, int $step = 30): array
    {
        $cursor = $day->copy()->setTimeFromTimeString($point->opens_at);
        $closes = $day->copy()->setTimeFromTimeString($point->closes_at);
        $slots = [];

        while ($cursor->copy()->addMinutes($duration)->lte($closes)) {
            $conflicts = $this->conflicts(
                AssignmentType::Installation,
                $cursor->copy(),
                $duration,
                $point,
            );

            if ($conflicts === []) {
                $slots[] = $cursor->copy();
            }

            $cursor->addMinutes($step);
        }

        return $slots;
    }

    /** Сколько постов занято в этом промежутке. */
    private function busyBays(ServicePoint $point, Carbon $start, Carbon $end, ?int $ignoreId): int
    {
        return $point->assignments()
            ->active()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->filter(fn (Assignment $a) => $a->overlaps($start, $end))
            ->count();
    }

    private function assigneeBusy(User $assignee, Carbon $start, Carbon $end, ?int $ignoreId): bool
    {
        return Assignment::where('assignee_id', $assignee->id)
            ->active()
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->contains(fn (Assignment $a) => $a->overlaps($start, $end));
    }

    /** Работа должна и начаться, и закончиться в рабочие часы сервиса. */
    private function withinWorkingHours(ServicePoint $point, Carbon $start, Carbon $end): bool
    {
        $opens = $start->copy()->setTimeFromTimeString($point->opens_at);
        $closes = $start->copy()->setTimeFromTimeString($point->closes_at);

        return $start->gte($opens) && $end->lte($closes);
    }
}
