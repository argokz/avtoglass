<?php

declare(strict_types=1);

namespace App\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AiCheckout extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['owner_scope', 'request_hash'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'result' => 'encrypted:array', 'privacy_consented_at' => 'immutable_datetime'];
    }
}
