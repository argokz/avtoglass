<?php

namespace App\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Единственное место, где решается, что покупатель видит в каталоге.
 *
 * Правило «только опубликованное» живёт здесь, а не в каждом контроллере:
 * иначе рано или поздно появится страница, где оно забыто, и в витрину
 * протекут неразобранные строки прайса.
 */
class ProductQuery
{
    public function paginate(ProductFilter $filter, int $perPage = 24): LengthAwarePaginator
    {
        return $filter->sort
            ->apply($this->build($filter))
            // в списке нужен только счётчик предложений: сами офферы на 24 карточки
            // это лишние сотни строк
            ->withCount('offers')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Сколько товаров каждого типа стекла есть под текущий фильтр — для чипсов. */
    public function countsByType(ProductFilter $filter): Collection
    {
        // считаем без учёта выбранного типа, иначе в фильтре останется
        // единственная кнопка — та, что уже нажата
        $filter = new ProductFilter(
            type: null,
            options: $filter->options,
            carModelId: $filter->carModelId,
            year: $filter->year,
            query: $filter->query,
            inStockOnly: $filter->inStockOnly,
            supplierId: $filter->supplierId,
        );

        return $this->build($filter)
            ->reorder()
            ->selectRaw('glass_type, COUNT(*) as total')
            ->groupBy('glass_type')
            ->pluck('total', 'glass_type');
    }

    /**
     * Марки стекла, доступные под текущий отбор, со счётчиками.
     *
     * Считаем без учёта уже выбранной марки — иначе в фильтре осталась бы
     * одна кнопка, та, что нажата, и вернуться к остальным было бы нечем.
     *
     * @return Collection<int,object{id:int,name:string,tier:string,total:int}>
     */
    public function countsBySupplier(ProductFilter $filter): Collection
    {
        $without = new ProductFilter(
            type: $filter->type,
            options: $filter->options,
            carModelId: $filter->carModelId,
            year: $filter->year,
            query: $filter->query,
            inStockOnly: $filter->inStockOnly,
        );

        return DB::table('offers')
            ->join('suppliers', 'suppliers.id', '=', 'offers.supplier_id')
            ->whereIn('offers.product_id', $this->build($without)->reorder()->select('products.id'))
            ->where('offers.is_active', true)
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.tier')
            ->orderByDesc(DB::raw('COUNT(DISTINCT offers.product_id)'))
            ->get([
                'suppliers.id',
                'suppliers.name',
                'suppliers.tier',
                DB::raw('COUNT(DISTINCT offers.product_id) as total'),
            ]);
    }

    /**
     * Оператор поиска без учёта регистра.
     *
     * В SQLite LIKE и так игнорирует регистр, в PostgreSQL — нет: там «camry»
     * не найдёт «Camry», и поиск по каталогу для покупателя просто перестанет
     * работать. ILIKE делает поведение одинаковым, включая кириллицу, которую
     * lower() в SQLite не берёт.
     */
    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Применимость: та ли это машина и тот ли год.
     *
     * Записи без указанных годов проходят фильтр по году намеренно. Год в
     * прайсах поставщиков стоит не всегда, и спрятать из-за этого стекло,
     * которое может подойти, дороже, чем показать лишнее: во втором случае
     * менеджер уточнит по VIN, в первом клиент просто уйдёт.
     */
    private function matchFitment(Builder $query, ProductFilter $filter): Builder
    {
        $query->where('car_model_id', $filter->carModelId);

        if ($filter->year !== null) {
            $query
                ->where(fn (Builder $q) => $q
                    ->whereNull('year_from')
                    ->orWhere('year_from', '<=', $filter->year))
                ->where(fn (Builder $q) => $q
                    ->whereNull('year_to')
                    ->orWhere('year_to', '>=', $filter->year));
        }

        return $query;
    }

    public function build(ProductFilter $filter): Builder
    {
        $query = Product::query()->where('is_published', true);

        if ($filter->carModelId !== null) {
            $query->whereHas(
                'fitments',
                fn (Builder $q) => $this->matchFitment($q, $filter)
            );
        }

        if ($filter->type !== null) {
            $query->where('glass_type', $filter->type->value);
        }

        foreach ($filter->options as $flag) {
            $query->where($flag, true);
        }

        if ($filter->inStockOnly) {
            $query->where('in_stock', true);
        }

        /*
         * Марка стекла. Отбор идёт по предложениям: карточка остаётся, если
         * хоть один поставщик её возит — иначе фильтр прятал бы товар,
         * который на самом деле есть, просто у другого производителя.
         */
        if ($filter->supplierId !== null) {
            $query->whereHas('offers', fn (Builder $q) => $q
                ->where('supplier_id', $filter->supplierId)
                ->where('is_active', true));
        }

        if ($filter->query !== null) {
            $operator = $this->likeOperator();

            $query->where(function (Builder $q) use ($filter, $operator) {
                $term = '%'.$filter->query.'%';

                // Еврокод покупатели вводят прямо с наклейки на стекле — это самый
                // точный способ попасть в товар, поэтому он в поиске равноправен с именем.
                $q->where('eurocode', $operator, $term)
                    ->orWhere('oem_code', $operator, $term)
                    ->orWhere('internal_code', $operator, $term)
                    ->orWhere('name', $operator, $term);
            });
        }

        return $query;
    }
}
