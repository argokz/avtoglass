<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fitment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function carModel(): BelongsTo
    {
        return $this->belongsTo(CarModel::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(CarGeneration::class, 'car_generation_id');
    }

    /** Подходит ли стекло к машине этого года выпуска. */
    public function coversYear(int $year): bool
    {
        if ($this->year_from && $year < $this->year_from) {
            return false;
        }

        if ($this->year_to && $year > $this->year_to) {
            return false;
        }

        return true;
    }
}
