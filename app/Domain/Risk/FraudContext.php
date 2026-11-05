<?php

declare(strict_types=1);

namespace App\Domain\Risk;

/** Входные данные для оценки риска заявки. */
final readonly class FraudContext
{
    public function __construct(
        public ?string $phoneHash,
        public ?string $ipHash,
        public ?float $amount,
        public string $source,
    ) {}
}
