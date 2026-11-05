<?php

namespace App\Models;

use App\Enums\GlassType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        $flush = static fn () => Once::flush();
        static::saved($flush);
        static::deleted($flush);

        /*
         * Сборка каталога даёт свои slug и подпись, а товар из CRM приходит
         * без них: в форме этих полей нет, и «Создать» падало на NOT NULL.
         * Подпись ручного товара случайная — со строками прайса он не
         * склеится, пока менеджер сам не решит, что это одно стекло.
         */
        static::creating(function (self $product): void {
            $product->slug ??= self::freshSlug((string) $product->name);
            $product->signature ??= hash('sha256', 'manual|'.Str::uuid());
        });
    }

    /** Адрес из названия плюс случайный хвост: два «Лобовое Toyota Camry» не столкнутся. */
    public static function freshSlug(string $name): string
    {
        return (Str::limit(Str::slug($name), 180, '') ?: 'steklo').'-'.Str::lower(Str::random(8));
    }

    /** Опции стекла — из них строятся фильтры и мастер подбора. */
    public const OPTION_FLAGS = [
        'sun_band' => 'Солнцезащитная полоса',
        'rain_sensor' => 'Датчик дождя',
        'camera' => 'Камера ADAS за лобовым стеклом, не камера заднего вида',
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

    /**
     * То, по чему на витрине реально выбирают стекло.
     * Короткие подписи — чипы, не карточка товара.
     */
    public const STOREFRONT_OPTIONS = [
        'camera' => 'Камера',
        'rain_sensor' => 'Датчик дождя',
        'sun_band' => 'Полоса',
        'heated_wipers' => 'Обогрев щёток',
        'heated_full' => 'Полный обогрев',
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

    /**
     * Отечественный товар. По правилам площадок РК он получает приоритет
     * в общей выдаче и отдельный бейдж — решаем это по коду страны,
     * а не по подстроке в названии.
     */
    public function isMadeInKz(): bool
    {
        return $this->origin_country === 'KZ';
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
            ? файлВитрины($this->image_path)
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
            // Свободный остаток: складской qty включает уже обещанное другим.
            'in_stock' => $offers->contains(fn (Offer $offer) => $offer->available() > 0),
        ])->save();
    }

    /** Минимальная цена витрины для этого покупателя. */
    public function sellingPriceMin(?Customer $buyer = null): ?float
    {
        if ($buyer?->seesWholesalePrices() !== true) {
            return $this->price_min !== null ? (float) $this->price_min : null;
        }

        $offers = $this->relationLoaded('offers')
            ? $this->offers->where('is_active', true)
            : $this->offers()->where('is_active', true)->get();

        $prices = $offers
            ->map(fn (Offer $offer) => $offer->sellingPrice($buyer))
            ->filter(fn ($price) => $price !== null && $price > 0);

        if ($prices->isNotEmpty()) {
            return (float) $prices->min();
        }

        if ($this->price_min === null) {
            return null;
        }

        return $buyer->wholesalePrice((float) $this->price_min);
    }

    public function sellingPriceMax(?Customer $buyer = null): ?float
    {
        if ($buyer?->seesWholesalePrices() !== true) {
            return $this->price_max !== null ? (float) $this->price_max : null;
        }

        $offers = $this->relationLoaded('offers')
            ? $this->offers->where('is_active', true)
            : $this->offers()->where('is_active', true)->get();

        $prices = $offers
            ->map(fn (Offer $offer) => $offer->sellingPrice($buyer))
            ->filter(fn ($price) => $price !== null && $price > 0);

        return $prices->isNotEmpty() ? (float) $prices->max() : $this->sellingPriceMin($buyer);
    }
}
