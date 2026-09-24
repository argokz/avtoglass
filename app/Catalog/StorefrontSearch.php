<?php

namespace App\Catalog;

use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\SessionBuyer;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Поиск с витрины: сначала машина, потом стекло.
 *
 * Товарный отбор идёт через SearchOffers; модели и марки остаются подсказками
 * «человек искал машину, а не еврокод».
 */
class StorefrontSearch
{
    public function __construct(private readonly SearchOffers $offers) {}

    public function find(Request $request): StorefrontSearchResult
    {
        $filter = ProductFilter::fromRequest($request);
        $query = $filter->query ?? trim((string) $request->query('q'));

        if (! $filter->isSearch()) {
            return new StorefrontSearchResult(
                query: $query === '' ? null : $query,
                models: collect(),
                brands: collect(),
                products: $this->emptyPage(),
            );
        }

        return new StorefrontSearchResult(
            query: $filter->query,
            models: $this->models($filter->query),
            brands: $this->brands($filter->query),
            products: $this->products($request, $filter),
        );
    }

    private function products(Request $request, ProductFilter $filter): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 24;
        $params = array_filter([
            'q' => $filter->query,
            'page' => $page,
            'per_page' => $perPage,
            'glass_type' => $filter->type?->value,
            'supplier_id' => $filter->supplierId,
            'in_stock_only' => $filter->inStockOnly,
            'car_model_id' => $filter->carModelId,
            'year' => $filter->year,
        ], fn ($value) => $value !== null && $value !== '');

        if ($filter->options !== []) {
            $params['options'] = array_fill_keys($filter->options, true);
        }

        $result = $this->offers->search(SessionBuyer::current(), $params);
        $productIds = [];
        foreach ($result['items'] as $item) {
            $id = (int) ($item['product_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $productIds, true)) {
                $productIds[] = $id;
            }
        }

        $products = $productIds === []
            ? collect()
            : Product::query()
                ->whereIn('id', $productIds)
                ->withCount('offers')
                ->when(текущийПокупатель()?->seesWholesalePrices(), fn ($q) => $q->with([
                    'offers' => fn ($o) => $o->where('is_active', true),
                ]))
                ->get()
                ->sortBy(fn (Product $product) => array_search($product->id, $productIds, true))
                ->values();

        return new Paginator(
            $products,
            $result['meta']['total'],
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()],
        );
    }

    /** @return Collection<int,CarModel> */
    private function models(string $query): Collection
    {
        $term = '%'.$query.'%';

        return CarModel::query()
            ->where('products_count', '>', 0)
            ->where(function ($q) use ($term) {
                $q->where('name', 'ilike', $term)
                    ->orWhere('name_ru', 'ilike', $term);
            })
            ->with('brand')
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    /** @return Collection<int,CarBrand> */
    private function brands(string $query): Collection
    {
        $term = '%'.$query.'%';

        return CarBrand::query()
            ->where('models_count', '>', 0)
            ->where(function ($q) use ($term) {
                $q->where('name', 'ilike', $term)
                    ->orWhere('name_ru', 'ilike', $term);
            })
            ->orderByDesc('models_count')
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    private function emptyPage(): LengthAwarePaginator
    {
        return new Paginator([], 0, 24);
    }
}
