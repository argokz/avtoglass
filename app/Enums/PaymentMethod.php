<?php

namespace App\Enums;

/**
 * Как клиент собирается платить. Карта уходит в Halyk сразу после заявки,
 * наличные — при получении или в сервисе. Без этого выбора на форме
 * человек не понимает, с него сейчас спишут или нет.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case Kaspi = 'kaspi';
    case Cash = 'cash';
    case Invoice = 'invoice';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Картой онлайн',
            self::Kaspi => 'Kaspi Pay',
            self::Cash => 'При получении',
            self::Invoice => 'Счёт на организацию',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Card => 'Halyk Pay: номер карты на форме банка',
            self::Kaspi => 'Перевод по ссылке или QR в приложении Kaspi.kz',
            self::Cash => 'Наличными или переводом, когда привезём или поставим',
            self::Invoice => 'Банковский перевод по счёту, поступление подтвердит менеджер',
        };
    }

    /**
     * Драйвер кассы для способа с онлайн-оплатой сразу после заявки.
     * null — платить сейчас нечем (наличные).
     */
    public function driver(): ?string
    {
        return match ($this) {
            self::Card => 'halyk',
            self::Kaspi => 'kaspi',
            self::Cash, self::Invoice => null,
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
