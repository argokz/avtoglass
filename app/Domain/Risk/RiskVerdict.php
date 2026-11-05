<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/** Решение провайдера: уровень и машиночитаемые причины. */
final readonly class RiskVerdict
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public RiskLevel $level,
        public array $reasons = [],
    ) {}

    public static function allow(): self
    {
        return new self(RiskLevel::Allow);
    }
}
