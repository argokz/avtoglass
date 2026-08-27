<?php

namespace App\Models;

use App\Catalog\ModelNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarModelAlias extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(CarBrand::class, 'car_brand_id');
    }

    /**
     * Готовый нормализатор со всеми псевдонимами из базы.
     * Одна выборка на всю сборку каталога — их десятки, а строк прайса тысячи.
     */
    public static function normalizer(): ModelNormalizer
    {
        $aliases = static::with('brand')->get()->mapWithKeys(fn (self $a) => [
            ($a->brand?->name ?? '').'|'.$a->alias => [
                'name' => $a->model_name,
                'generation' => $a->generation,
            ],
        ])->all();

        return new ModelNormalizer($aliases);
    }
}
