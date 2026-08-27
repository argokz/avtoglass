<?php

namespace App\Catalog;

use App\Enums\GlassType;
use Illuminate\Support\Facades\DB;

/**
 * Последний рубеж перед витриной.
 *
 * Проверяет не разбор и не сборку, а результат: что бы ни случилось выше
 * по конвейеру — сменился формат прайса, поставщик прислал пустые цены,
 * кто-то поправил карточку руками — покупатель не должен увидеть товар
 * без цены или с ценой, в которую невозможно поверить.
 */
class PublishingGuard
{
    /**
     * Ниже этой цены стекло не бывает: самое дешёвое боковое в выгрузке стоит
     * около трёх тысяч. Всё, что дешевле, — ошибка в прайсе, а не находка.
     */
    public const MIN_PLAUSIBLE_GLASS_PRICE = 1000;

    /** @return int сколько карточек сняли с витрины */
    public function apply(): int
    {
        return $this->hideWithoutPrice() + $this->hideImplausiblyCheapGlass();
    }

    private function hideWithoutPrice(): int
    {
        return DB::update('
            UPDATE products SET is_published = false, needs_review = true
            WHERE is_published = true AND (price_min IS NULL OR price_min <= 0)
        ');
    }

    /**
     * Расходники сюда не попадают: скотч, лезвия и салфетки столько и стоят,
     * а вот заднее стекло за десять тенге — это остаток или единица измерения,
     * случайно попавшая в колонку цены.
     */
    private function hideImplausiblyCheapGlass(): int
    {
        return DB::update(
            'UPDATE products SET is_published = false, needs_review = true
             WHERE is_published = true AND price_min < ? AND glass_type NOT IN (?, ?)',
            [self::MIN_PLAUSIBLE_GLASS_PRICE, GlassType::Consumable->value, GlassType::Service->value]
        );
    }
}
