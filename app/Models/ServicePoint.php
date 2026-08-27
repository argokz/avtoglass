<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePoint extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected $attributes = [
        'bays' => 1,
        'is_active' => true,
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
