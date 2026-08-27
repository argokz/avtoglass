<?php

namespace App\Enums;

enum BodyType: string
{
    case Sedan = 'sedan';
    case Hatchback = 'hatchback';
    case Liftback = 'liftback';
    case Wagon = 'wagon';
    case Suv = 'suv';
    case Van = 'van';
    case Pickup = 'pickup';
    case Mpv = 'mpv';
    case Coupe = 'coupe';
    case Cabrio = 'cabrio';
    case Truck = 'truck';
    case Bus = 'bus';

    public function label(): string
    {
        return match ($this) {
            self::Sedan => 'Седан',
            self::Hatchback => 'Хэтчбек',
            self::Liftback => 'Лифтбек',
            self::Wagon => 'Универсал',
            self::Suv => 'Внедорожник',
            self::Van => 'Фургон',
            self::Pickup => 'Пикап',
            self::Mpv => 'Минивэн',
            self::Coupe => 'Купе',
            self::Cabrio => 'Кабриолет',
            self::Truck => 'Грузовик',
            self::Bus => 'Автобус',
        };
    }

    /** Обозначения кузова, как они встречаются в прайсах поставщиков. */
    public static function fromPriceList(string $token): ?self
    {
        return match (mb_strtolower(trim($token))) {
            'sed', 'sedan' => self::Sedan,
            'hbk', 'hatchback', 'hb' => self::Hatchback,
            'lbk', 'liftback' => self::Liftback,
            'wag', 'wagon', 'tour',
            'break', 'universal', 'sw' => self::Wagon,
            'suv', 'utility', 'crossover' => self::Suv,
            'van', 'minivan' => self::Van,
            'pick-up', 'pickup', 'pick' => self::Pickup,
            'mpv' => self::Mpv,
            'cou', 'coupe' => self::Coupe,
            'cabrio', 'cab' => self::Cabrio,
            'truck', 'cabover' => self::Truck,
            'bus' => self::Bus,
            default => null,
        };
    }
}
