<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Held = 'held';          // стекло отложено под заказ, со склада не уйдёт
    case Released = 'released';  // заказ отменён или бронь протухла — вернули в продажу
    case Consumed = 'consumed';  // отдали клиенту, списали с остатка

    public function label(): string
    {
        return match ($this) {
            self::Held => 'В резерве',
            self::Released => 'Снят',
            self::Consumed => 'Отгружен',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Held => 'warning',
            self::Released => 'gray',
            self::Consumed => 'success',
        };
    }
}
