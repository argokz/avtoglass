<?php

namespace App\Catalog;

use App\Models\Fitment;

/**
 * Достраивает годы применимости по поколению.
 *
 * В прайсах годы пишут по-разному: где-то диапазоном («Camry 2006–2011»),
 * где-то одним поколением («Camry 70», «CAMRY XV70 17»). Во втором случае
 * разбор находит поколение, но годы в применимости остаются пустыми, —
 * а пустые годы в подборе проходят любой фильтр, и Camry 70 попадает
 * в выдачу за 2008-й.
 *
 * Само поколение свои годы обычно знает: их принесла другая строка прайса,
 * где диапазон был написан. Остаётся перенести их на применимость.
 *
 * Только заполнение пустых: собственные годы применимости точнее, потому что
 * одно стекло внутри поколения может идти не весь его срок (дорестайлинг,
 * смена поставщика ветрового стекла).
 */
class FitmentYears
{
    /** @return int сколько применимостей удалось уточнить */
    public function backfill(): int
    {
        $filled = 0;

        Fitment::query()
            ->whereNull('year_from')
            ->whereNotNull('car_generation_id')
            ->with('generation')
            ->chunkById(500, function ($fitments) use (&$filled) {
                foreach ($fitments as $fitment) {
                    $generation = $fitment->generation;

                    if ($generation?->year_from === null) {
                        continue;   // поколение само без годов — переносить нечего
                    }

                    $yearFrom = $generation->year_from;
                    // Конец берём достроенный: у поколения его тоже почти
                    // никогда не пишут, но следующее поколение его задаёт.
                    $yearTo = $fitment->year_to ?: $generation->effectiveYearTo();

                    /*
                     * Одно стекло приходит из прайса двумя строками: в одной
                     * годы написаны, в другой нет. Обе заводят применимость,
                     * и после заполнения годами вторая становится точной копией
                     * первой — а на этой паре стоит уникальный ключ.
                     *
                     * Дубль не обновляем, а убираем: это та же применимость,
                     * просто пришедшая без годов.
                     */
                    $двойник = Fitment::query()
                        ->where('product_id', $fitment->product_id)
                        ->where('car_model_id', $fitment->car_model_id)
                        ->where('year_from', $yearFrom)
                        ->where('year_to', $yearTo)
                        ->where('body_type', $fitment->body_type)
                        ->whereKeyNot($fitment->getKey())
                        ->exists();

                    if ($двойник) {
                        $fitment->delete();

                        continue;
                    }

                    $fitment->update([
                        'year_from' => $yearFrom,
                        'year_to' => $yearTo,
                    ]);

                    $filled++;
                }
            });

        return $filled;
    }
}
