<?php

namespace App\Enums;

enum GlassType: string
{
    case Windshield = 'windshield';   // ветровое (лобовое)
    case Rear = 'rear';         // заднее
    case Side = 'side';         // боковое, опускное
    case Quarter = 'quarter';      // форточка, глухое
    case Roof = 'roof';         // крыша, люк, панорама
    case Consumable = 'consumable';   // молдинг, клей, подложка, праймер, инструмент
    case Service = 'service';      // установка, ремонт, калибровка ADAS

    public function label(): string
    {
        return match ($this) {
            self::Windshield => 'Лобовое стекло',
            self::Rear => 'Заднее стекло',
            self::Side => 'Боковое стекло',
            self::Quarter => 'Форточка',
            self::Roof => 'Стекло крыши',
            self::Consumable => 'Расходники и молдинги',
            self::Service => 'Услуги',
        };
    }

    /** Стекло это или сопутствующий товар/услуга. */
    public function isGlass(): bool
    {
        return ! in_array($this, [self::Consumable, self::Service], true);
    }
}
