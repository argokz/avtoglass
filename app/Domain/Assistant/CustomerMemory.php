<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Illuminate\Database\Eloquent\Model;

final class CustomerMemory extends Model
{
    protected $table = 'assistant_memories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['facts' => 'encrypted:array', 'consented_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
