<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Illuminate\Database\Eloquent\Model;

final class ShoppingSession extends Model
{
    protected $table = 'assistant_sessions';

    protected $primaryKey = 'conversation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => 'encrypted:array', 'version' => 'integer'];
    }
}
