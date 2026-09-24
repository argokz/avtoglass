<?php

namespace App\Enums;

/**
 * Опт не включается галочкой на регистрации: иначе любой указал бы «сервис»
 * и увидел бы закупочные условия. Заявку принимает менеджер.
 */
enum WholesaleStatus: string
{
    case Retail = 'retail';
    case Pending = 'pending';
    case Wholesale = 'wholesale';

    public function label(): string
    {
        return match ($this) {
            self::Retail => 'Розница',
            self::Pending => 'Заявка на опт',
            self::Wholesale => 'Опт',
        };
    }

    public function seesWholesalePrices(): bool
    {
        return $this === self::Wholesale;
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $acc, self $status) => $acc + [$status->value => $status->label()],
            []
        );
    }
}
