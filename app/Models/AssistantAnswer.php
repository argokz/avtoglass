<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Model;

final class AssistantAnswer extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        self::saved(fn () => app(StorefrontCache::class)->bump());
        self::deleted(fn () => app(StorefrontCache::class)->bump());
    }

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'valid_until' => 'immutable_datetime'];
    }
}
