<?php

namespace App\Enums;

/**
 * Наряд — конкретная работа с конкретным исполнителем и временем.
 * Установка занимает пост в сервисе, доставка — курьера и его маршрут;
 * общего у них только то, что и то и другое кому-то назначают на время.
 */
enum AssignmentType: string
{
    case Installation = 'installation';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Installation => 'Установка',
            self::Delivery => 'Доставка',
        };
    }

    /** Кто выполняет такой наряд. */
    public function performedBy(): UserRole
    {
        return match ($this) {
            self::Installation => UserRole::Installer,
            self::Delivery => UserRole::Courier,
        };
    }

    /** Установка привязана к посту в сервисе, доставка — к адресу клиента. */
    public function needsServicePoint(): bool
    {
        return $this === self::Installation;
    }

    /** Сколько минут занимает по умолчанию. */
    public function defaultDuration(): int
    {
        return match ($this) {
            self::Installation => 120,  // снять старое, подготовить проём, вклеить, дать схватиться
            self::Delivery => 40,
        };
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
