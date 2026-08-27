<?php

namespace App\Catalog;

use App\Models\CarGeneration;
use App\Models\CarModel;
use Illuminate\Support\Facades\DB;

/**
 * Годы выпуска, которые имеет смысл предлагать по конкретной модели.
 *
 * Год — единственное, что покупатель про свою машину знает точно. Поколение
 * («XV40», «E60») он не назовёт, VIN искать пойдёт не всякий, а год выпуска
 * помнит каждый. Поэтому в подборе спрашиваем год, а поколение показываем
 * в ответ — как подтверждение, что мы поняли, о какой машине речь.
 *
 * Список строится по применимостям, а не по справочнику поколений: у него
 * покрытие втрое меньше, и половина моделей осталась бы вообще без выбора.
 */
class ModelYears
{
    /**
     * Годы по убыванию: свежие машины ищут чаще старых.
     *
     * @return array<int,int>
     */
    public function for(CarModel $model): array
    {
        $bounds = DB::table('fitments')
            ->join('products', 'products.id', '=', 'fitments.product_id')
            ->where('fitments.car_model_id', $model->getKey())
            ->where('products.is_published', true)
            ->selectRaw('MIN(fitments.year_from) as first_year')
            // Год подставляем в текст запроса, а не параметром: PostgreSQL
            // не может вывести тип placeholder-а внутри COALESCE и падает.
            // Значение вычисляем сами из даты, снаружи оно не приходит.
            ->selectRaw('MAX(COALESCE(fitments.year_to, '.$this->currentYear().')) as last_year')
            ->first();

        if ($bounds?->first_year === null) {
            return [];
        }

        $from = (int) $bounds->first_year;
        $to = min((int) $bounds->last_year, $this->currentYear());

        return $to >= $from ? range($to, $from) : [];
    }

    /**
     * Какое это поколение.
     *
     * Возвращает null, когда поколения для года нет: справочник заполнен
     * не везде, и придумывать за него нельзя — подпись под подбором должна
     * либо быть верной, либо отсутствовать.
     */
    public function generationFor(CarModel $model, ?int $year): ?CarGeneration
    {
        if ($year === null) {
            return null;
        }

        return CarGeneration::query()
            ->where('car_model_id', $model->getKey())
            /*
             * Поколения без известного года начала сюда не попадают.
             *
             * Про такое поколение нельзя утверждать, что оно покрывает 2006-й, —
             * мы просто не знаем, когда оно началось. Подпись под подбором
             * обязана быть либо верной, либо отсутствовать.
             *
             * Заодно это снимает разницу движков: PostgreSQL при сортировке
             * по убыванию ставит NULL первыми, SQLite — последними, и
             * поколение без годов перебивало настоящее.
             */
            ->whereNotNull('year_from')
            ->where('year_from', '<=', $year)
            ->where(fn ($q) => $q->whereNull('year_to')->orWhere('year_to', '>=', $year))
            // Рестайлинг — уточнение внутри поколения, а не отдельная машина:
            // если подошли оба, показываем основное.
            ->orderBy('is_restyling')
            ->orderByDesc('year_from')
            ->first();
    }

    /** Следующий календарный: новые модели продаются как «2027» уже осенью 2026-го. */
    private function currentYear(): int
    {
        return (int) date('Y') + 1;
    }
}
