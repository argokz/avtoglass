<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'markup_percent' => 'decimal:2',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function tierLabel(): string
    {
        return match ($this->tier) {
            'economy' => 'Эконом',
            'premium' => 'Премиум',
            'oem' => 'Оригинал / OEM',
            default => 'Стандарт',
        };
    }
}
