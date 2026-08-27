<?php

namespace App\Logistics;

/** Почему на это время записать нельзя. */
enum ScheduleConflict: string
{
    case PointBusy = 'point_busy';
    case AssigneeBusy = 'assignee_busy';
    case PointClosed = 'point_closed';
    case InThePast = 'in_the_past';

    public function message(): string
    {
        return match ($this) {
            self::PointBusy => 'В сервисе на это время нет свободного поста',
            self::AssigneeBusy => 'У исполнителя уже есть наряд на это время',
            self::PointClosed => 'Сервис в это время закрыт',
            self::InThePast => 'Нельзя записать на прошедшее время',
        };
    }
}
