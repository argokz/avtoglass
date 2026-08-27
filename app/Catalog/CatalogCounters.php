<?php

namespace App\Catalog;

use Illuminate\Support\Facades\DB;

/**
 * Счётчики для меню каталога.
 *
 * Считаем только опубликованное: в меню не должно быть марки, которая ведёт
 * на пустую страницу.
 *
 * Осторожно с именами: колонка models_count совпадает с алиасом, который
 * генерирует withCount('models'), и тихо перебивает его значением из таблицы.
 * Поэтому счётчики обязаны быть заполнены, а не просто существовать.
 *
 * Вынесено из сборки каталога, потому что менять справочник моделей умеет
 * не только она: слияние дублей тоже обязано оставить счётчики верными,
 * иначе в меню повиснут марки, ведущие в пустоту.
 */
class CatalogCounters
{
    public function refresh(): void
    {
        DB::statement('
            UPDATE car_models SET products_count = (
                SELECT COUNT(DISTINCT fitments.product_id)
                FROM fitments
                JOIN products ON products.id = fitments.product_id
                WHERE fitments.car_model_id = car_models.id AND products.is_published = true
            )
        ');

        DB::statement('
            UPDATE car_brands SET models_count = (
                SELECT COUNT(*) FROM car_models
                WHERE car_models.car_brand_id = car_brands.id AND car_models.products_count > 0
            )
        ');
    }
}
