<?php

namespace App\Http\Controllers;

use App\Catalog\ProductQuery;
use App\Enums\GlassType;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Витрина каталога: марка -> модель -> список стёкол.
 * Отбор товаров целиком в ProductQuery, здесь только сборка страницы.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly ProductQuery $products) {}

    /** Все марки, у которых есть что показать. */
    public function brands(Request $request): View
    {
        $brands = CarBrand::where('models_count', '>', 0)->orderBy('name')->get();

        return view('catalog.brands', [
            'brands' => $brands->groupBy(fn (CarBrand $b) => mb_substr($b->name, 0, 1)),
            'popular' => $brands->sortByDesc('models_count')->take(12),
            'glassType' => GlassType::tryFrom((string) $request->query('type')),
        ]);
    }

    public function models(Request $request, CarBrand $brand): View
    {
        return view('catalog.models', [
            'brand' => $brand,
            'models' => $brand->models()
                ->where('products_count', '>', 0)
                ->orderBy('name')
                ->get(),
            'glassType' => GlassType::tryFrom((string) $request->query('type')),
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

    /**
     * Расходники, которые не привязаны к машине.
     *
     * Молдинг, клей и полимер универсальны: подбирать их по марке нельзя,
     * и в дереве «марка → модель → стекло» им места нет. Раньше они просто
     * не доезжали до витрины, хотя это ходовой товар, который берут вместе
     * со стеклом и отдельно.
     */
    public function consumables(Request $request): View
    {
        $query = Product::query()
            ->where('glass_type', GlassType::Consumable->value)
            ->where('is_published', true)
            ->whereDoesntHave('fitments')
            ->with(['offers.supplier']);

        if ($поиск = trim((string) $request->query('q'))) {
            $query->where('name', 'ilike', '%'.$поиск.'%');
        }

        return view('catalog.consumables', [
            'products' => $query->orderBy('price_min')->orderBy('name')->paginate(36)->withQueryString(),
            'q' => $поиск,
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

        $query = Product::where('is_published', true)
            ->withCount('offers')
            ->whereKeyNot($product->id)
            ->whereHas('fitments', fn ($q) => $q->whereIn('car_model_id', $modelIds))
            ->orderByRaw('price_min IS NULL')
            ->orderBy('price_min')
            ->limit(6);

        if (текущийПокупатель()?->seesWholesalePrices()) {
            $query->with(['offers' => fn ($q) => $q->where('is_active', true)]);
        }

        return $query->get();
    }
}
