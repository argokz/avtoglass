<?php

namespace App\Enums;

/**
 * Что клиент хочет получить. Для стекла это не «способ доставки», а разные
 * работы: установка занимает слот мастера, выезд — мастера и адрес,
 * доставка — курьера, самовывоз — ни того, ни другого.
 */
enum FulfillmentType: string
{
    case Installation = 'installation';
    case Visit = 'visit';
    case Delivery = 'delivery';
    case Pickup = 'pickup';

    public function label(): string
    {
        return match ($this) {
            self::Installation => 'Замена в сервисе',
            self::Visit => 'Установка с выездом',
            self::Delivery => 'Доставка стекла',
            self::Pickup => 'Самовывоз',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Installation => 'Привезите машину — заменим на посту',
            self::Visit => 'Мастер приедет к вам со стеклом',
            self::Delivery => 'Привезём стекло, без установки',
            self::Pickup => 'Заберёте стекло со склада сами',
        };
    }

    /** Нужен ли адрес: без него курьеру и выездному мастеру ехать некуда. */
    public function needsAddress(): bool
    {
        return $this === self::Delivery || $this === self::Visit;
    }

    /** В сумму входит работа по установке. */
    public function includesInstallation(): bool
    {
        return $this === self::Installation || $this === self::Visit;
    }

    /** Показать поля желаемой даты. */
    public function needsSlot(): bool
    {
        return $this->includesInstallation();
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
