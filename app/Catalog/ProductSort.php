<?php

namespace App\Catalog;

use Illuminate\Database\Eloquent\Builder;

enum ProductSort: string
{
    case PriceAsc = 'price';
    case PriceDesc = 'price-desc';
    case Popular = 'popular';

    public function label(): string
    {
        return match ($this) {
            self::PriceAsc => 'Сначала дешёвые',
            self::PriceDesc => 'Сначала дорогие',
            self::Popular => 'Популярные',
        };
    }

    public function apply(Builder $query): Builder
    {
        return match ($this) {
            // Карточки без цены всегда в конце: пустая цена не должна выигрывать
            // сортировку по возрастанию только потому, что NULL меньше любого числа.
            self::PriceAsc => $query->orderByRaw('price_min IS NULL')->orderBy('price_min'),
            self::PriceDesc => $query->orderByRaw('price_min IS NULL')->orderByDesc('price_min'),
            self::Popular => $query->orderByDesc('views')->orderBy('price_min'),
        };
    }
}
