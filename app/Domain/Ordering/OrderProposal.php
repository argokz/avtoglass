<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use Illuminate\Database\Eloquent\Model;

final class OrderProposal extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
