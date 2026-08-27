<?php

namespace App\Http\Controllers;

use App\Catalog\ProductFilter;
use App\Catalog\ProductQuery;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
            'brands' => CarBrand::where('models_count', '>', 0)->orderBy('name')->get(),
            'popular' => CarBrand::where('models_count', '>', 0)
                ->orderByDesc('models_count')
                ->limit(12)
                ->get(),
            'stats' => [
                'products' => Product::where('is_published', true)->count(),
                'brands' => CarBrand::where('models_count', '>', 0)->count(),
            ],
        ]);
    }

    /** Второй шаг подбора: модели выбранной марки. Дёргается селектом на главной. */
    public function models(CarBrand $brand): JsonResponse
    {
        return response()->json(
            $brand->models()
                ->where('products_count', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
        );
    }

    /** Поиск по названию карточки, еврокоду и артикулам. */
    public function search(Request $request, ProductQuery $products): View
    {
        $filter = ProductFilter::fromRequest($request);

        return view('catalog.search', [
            'q' => $filter->query ?? trim((string) $request->query('q')),
            'products' => $filter->isSearch() ? $products->paginate($filter) : collect(),
        ]);
    }

    /** Подбор с главной: марка + модель -> страница модели. */
    public function pick(Request $request)
    {
        $data = $request->validate([
            'brand' => ['required', 'exists:car_brands,id'],
            'model' => ['nullable', 'exists:car_models,id'],
        ]);

        $brand = CarBrand::findOrFail($data['brand']);

        if (empty($data['model'])) {
            return redirect()->route('catalog.models', $brand);
        }

        $model = CarModel::findOrFail($data['model']);

        return redirect()->route('catalog.products', [$brand, $model]);
    }
}
