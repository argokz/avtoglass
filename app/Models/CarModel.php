<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarModel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_popular' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(CarBrand::class, 'car_brand_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(CarGeneration::class);
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(Fitment::class);
    }

    /**
     * Адрес снимка модели на витрине.
     *
     * Силуэт SVG, который когда-то сохраняли как «фото», снимком не является:
     * покупатель видит одну и ту же машинку у Camry и у Matiz. Для таких
     * путей отдаём null — шаблон рисует тематическую заглушку сам.
     */
    public function imageUrl(): ?string
    {
        return $this->hasPhoto()
            ? файлВитрины($this->image_path)
            : null;
    }

    public function hasPhoto(): bool
    {
        return self::pathIsRasterPhoto($this->image_path);
    }

    public static function pathIsRasterPhoto(?string $path): bool
    {
        if (! filled($path)) {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'], true);
    }
}
