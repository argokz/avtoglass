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
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Картой онлайн',
            self::Cash => 'При получении',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Card => 'Halyk Pay: номер карты на форме банка',
            self::Cash => 'Наличными или переводом, когда привезём или поставим',
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
