<?php

namespace App\Http\Controllers;

use App\Catalog\ProductQuery;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\Service;
use Illuminate\View\View;

/**
 * Витрина каталога: марка -> модель -> список стёкол.
 * Отбор товаров целиком в ProductQuery, здесь только сборка страницы.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly ProductQuery $products) {}

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

    public function products(CarBrand $brand, CarModel $model): View
    {
        abort_unless($model->car_brand_id === $brand->id, 404);

        // Фильтры и выдачу собирает Livewire-компонент: состояние живёт
        // в адресной строке, и контроллеру остаётся только сама страница.
        return view('catalog.products', [
            'brand' => $brand,
            'model' => $model,
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
            // Цену работы держит прайс работ, а не карточка: она одна на весь
            // тип стекла, и менеджер правит её в одном месте.
            'installation' => Service::defaultFor($product->glass_type),
            'calibration' => $product->camera
                ? Service::where('slug', 'kalibrovka-adas')->active()->first()
                : null,
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
