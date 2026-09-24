<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarBrand extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_popular' => 'boolean',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(CarModel::class);
    }

    /**
     * Логотип марки или null.
     *
     * Список марок без логотипов — это алфавитный указатель: покупатель ищет
     * свою машину глазами и по значку находит её быстрее, чем читая названия.
     * Но заливают логотипы вручную, поэтому шаблон обязан пережить их отсутствие.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? файлВитрины($this->logo_path)
            : null;
    }
}
