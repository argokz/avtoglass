<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CheckoutQuote extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['snapshot', 'source_hash', 'created_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'draft_version' => 'integer', 'snapshot' => 'encrypted:array', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $quote) {
            if ($quote->isDirty(['id', 'draft_id', 'draft_version', 'version', 'snapshot', 'source_hash', 'expires_at', 'created_at'])) {
                throw new LogicException('Quote terms are immutable; prepare a new quote.');
            }
        });
    }
}
