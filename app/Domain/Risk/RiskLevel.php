<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/** Вердикт антифрода по заявке. */
enum RiskLevel: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Чистая',
            self::Review => 'На проверке',
            self::Block => 'Заблокирована',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Allow => 'success',
            self::Review => 'warning',
            self::Block => 'danger',
        };
    }
}
