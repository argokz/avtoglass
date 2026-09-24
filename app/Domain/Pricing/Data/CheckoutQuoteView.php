<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Data;

use App\Domain\Pricing\Models\CheckoutQuote;
use JsonSerializable;

final readonly class CheckoutQuoteView implements JsonSerializable
{
    private function __construct(public string $id, public int $version, private array $data) {}

    public static function fromModel(CheckoutQuote $quote, int $currentDraftVersion): self
    {
        $status = $quote->status;
        if ($status === 'prepared') {
            $status = $quote->draft_version !== $currentDraftVersion ? 'superseded' : ($quote->expires_at->lte(now()) ? 'expired' : 'prepared');
        }

        return new self($quote->id, $quote->version, [
            ...$quote->snapshot, 'draft_id' => $quote->draft_id, 'draft_version' => $quote->draft_version,
            'status' => $status, 'created_at' => $quote->created_at->utc()->toIso8601String(),
            'expires_at' => $quote->expires_at->utc()->toIso8601String(),
        ]);
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'version' => $this->version, ...$this->data];
    }
}
