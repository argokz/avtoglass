<?php

namespace App\Enums;

/**
 * Что клиент хочет получить. Для стекла это не «способ доставки», а разные
 * работы: установка занимает слот мастера, доставка — курьера, самовывоз —
 * ни того, ни другого.
 */
enum FulfillmentType: string
{
    case Installation = 'installation'; // замена у нас в сервисе
    case Delivery = 'delivery';     // привезти стекло клиенту
    case Pickup = 'pickup';       // забрать самому

    public function label(): string
    {
        return match ($this) {
            self::Installation => 'Замена в сервисе',
            self::Delivery => 'Доставка',
            self::Pickup => 'Самовывоз',
        };
    }

    /** Нужен ли адрес: без него курьеру ехать некуда. */
    public function needsAddress(): bool
    {
        return $this === self::Delivery;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $t) => $acc + [$t->value => $t->label()],
            []
        );
    }
}
