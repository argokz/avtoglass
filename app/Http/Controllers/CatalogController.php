<?php

namespace App\Http\Controllers;

use App\Catalog\ModelYears;
use App\Catalog\ProductFilter;
use App\Catalog\ProductQuery;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Витрина каталога: марка -> модель -> список стёкол.
 * Отбор товаров целиком в ProductQuery, здесь только сборка страницы.
 */
class CatalogController extends Controller
{
    public function __construct(
        private readonly ProductQuery $products,
        private readonly ModelYears $years,
    ) {}

    /** Все марки, у которых есть что показать. */
    public function brands(): View
    {
        $brands = CarBrand::where('models_count', '>', 0)->orderBy('name')->get();

        return view('catalog.brands', [
            'brands' => $brands->groupBy(fn (CarBrand $b) => mb_substr($b->name, 0, 1)),
            'popular' => $brands->sortByDesc('models_count')->take(12),
        ]);
    }

    public function models(CarBrand $brand): View
    {
        return view('catalog.models', [
            'brand' => $brand,
            'models' => $brand->models()
                ->where('products_count', '>', 0)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function products(Request $request, CarBrand $brand, CarModel $model): View
    {
        abort_unless($model->car_brand_id === $brand->id, 404);

        $filter = ProductFilter::fromRequest($request, carModelId: $model->id);

        return view('catalog.products', [
            'brand' => $brand,
            'model' => $model,
            'filter' => $filter,
            'products' => $this->products->paginate($filter),
            'types' => $this->products->countsByType($filter),
            'years' => $this->years->for($model),
            // Поколение показываем в ответ на выбранный год: покупатель
            // называет год, а «XV40» видит как подтверждение, что мы поняли,
            // о какой машине речь.
            'generation' => $this->years->generationFor($model, $filter->year),
        ]);
    }

    public function product(Product $product): View
    {
        abort_unless($product->is_published, 404);

        $product->increment('views');
        $product->load(['offers.supplier', 'fitments.carModel.brand']);

        return view('catalog.product', [
            'product' => $product,
            'related' => $this->related($product),
        ]);
    }

    /** «Ещё смотрят»: другие стёкла для той же машины. */
    private function related(Product $product)
    {
        $modelIds = $product->fitments->pluck('car_model_id');

        if ($modelIds->isEmpty()) {
            return collect();
        }

        return Product::where('is_published', true)
            ->withCount('offers')
            ->whereKeyNot($product->id)
            ->whereHas('fitments', fn ($q) => $q->whereIn('car_model_id', $modelIds))
            ->orderByRaw('price_min IS NULL')
            ->orderBy('price_min')
            ->limit(6)
            ->get();
    }
}
