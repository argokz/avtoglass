<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\CanonicalJson;

final readonly class QuoteCalculation
{
    public function __construct(public array $snapshot, private array $sourceFacts) {}

    public function fingerprint(): string
    {
        return hash_hmac('sha256', CanonicalJson::encode(['snapshot' => $this->snapshot, 'sources' => $this->sourceFacts]), (string) config('app.key'));
    }
}
