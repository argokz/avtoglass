<?php

declare(strict_types=1);

namespace App\Domain\Shared\Commands;

use Illuminate\Database\Eloquent\Model;

final class IdempotencyRecord extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['owner_scope', 'key_hash', 'request_hash', 'response_payload'];

    protected function casts(): array
    {
        return ['response_payload' => 'encrypted:array', 'response_status' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
