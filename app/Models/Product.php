<?php

namespace App\Models;

use App\Enums\GlassType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** Опции стекла — из них строятся фильтры и мастер подбора. */
    public const OPTION_FLAGS = [
        'sun_band' => 'Солнцезащитная полоса',
        'rain_sensor' => 'Датчик дождя',
        'camera' => 'Камера ADAS',
        'heated_wipers' => 'Обогрев зоны щёток',
        'heated_full' => 'Полный электрообогрев',
        'heated_rear' => 'Обогрев заднего стекла',
        'antenna' => 'Антенна',
        'hud' => 'Проекционный дисплей',
        'acoustic' => 'Шумопоглощающее',
        'atermal' => 'Атермальное',
        'silkscreen' => 'Шелкография',
        'rhd' => 'Правый руль',
        'mirror_bracket' => 'Держатель зеркала',
        'vin_window' => 'Окно под VIN',
    ];

    protected function casts(): array
    {
        return [
            'glass_type' => GlassType::class,
            'sun_band' => 'boolean',
            'rain_sensor' => 'boolean',
            'camera' => 'boolean',
            'heated_wipers' => 'boolean',
            'heated_full' => 'boolean',
            'heated_rear' => 'boolean',
            'antenna' => 'boolean',
            'hud' => 'boolean',
            'acoustic' => 'boolean',
            'atermal' => 'boolean',
            'silkscreen' => 'boolean',
            'rhd' => 'boolean',
            'mirror_bracket' => 'boolean',
            'vin_window' => 'boolean',
            'is_published' => 'boolean',
            'in_stock' => 'boolean',
            'needs_review' => 'boolean',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function fitments(): HasMany
    {
        return $this->hasMany(Fitment::class);
    }

    /** Включённые опции — для вывода в карточке. */
    /**
     * Адрес фотографии или null.
     *
     * Null здесь — рабочее состояние, а не ошибка: стёкла приходят из прайсов
     * без картинок, и снимут их в лучшем случае для ходовых позиций. Шаблоны
     * обязаны это учитывать и рисовать значок типа стекла.
     */
    public function imageUrl(): ?string
    {
        return $this->image_path
            ? Storage::disk('public')->url($this->image_path)
            : null;
    }

    public function activeOptions(): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $label, string $flag) => $this->{$flag} ? $label : null,
                self::OPTION_FLAGS,
                array_keys(self::OPTION_FLAGS),
            )
        ));
    }

    /** Пересчёт витринных цен и наличия по активным офферам. */
    public function refreshPricing(): void
    {
        $offers = $this->offers()->where('is_active', true)->get();
        $prices = $offers->pluck('price_retail')->filter()->map(fn ($p) => (float) $p);

        $this->forceFill([
            'price_min' => $prices->min(),
            'price_max' => $prices->max(),
            'in_stock' => $offers->sum('stock_qty') > 0,
        ])->save();
    }
}
