<?php

namespace App\Http\Controllers;

use App\Catalog\StorefrontSearch;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\Service;
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
            'popularModels' => CarModel::where('products_count', '>', 0)
                ->with('brand')
                ->orderByDesc('products_count')
                ->limit(8)
                ->get(),
            /*
             * Цены на работы — с главной, а не «уточняйте по телефону».
             * Это первый вопрос покупателя, и человек, которому ответили
             * до звонка, звонит уже готовым покупать.
             */
            'services' => Service::where('is_active', true)->orderBy('sort')->get(),
            'stats' => [
                'products' => Product::where('is_published', true)->count(),
                'brands' => CarBrand::where('models_count', '>', 0)->count(),
                'models' => CarModel::where('products_count', '>', 0)->count(),
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

    /** Поиск по машине, еврокоду и названию карточки. */
    public function search(Request $request, StorefrontSearch $search): View
    {
        $hits = $search->find($request);

        return view('catalog.search', [
            'q' => $hits->query ?? trim((string) $request->query('q')),
            'models' => $hits->models,
            'brands' => $hits->brands,
            'products' => $hits->products,
        ]);
    }

    /** Подбор с главной: марка + модель (+ год) → страница модели. */
    public function pick(Request $request)
    {
        $data = $request->validate([
            'brand' => ['required', 'exists:car_brands,id'],
            'model' => ['nullable', 'exists:car_models,id'],
            'year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
        ]);

        $brand = CarBrand::findOrFail($data['brand']);
        $year = isset($data['year']) ? (int) $data['year'] : null;

        if (empty($data['model'])) {
            return redirect()->route('catalog.models', $brand);
        }

        $model = CarModel::findOrFail($data['model']);

        return redirect()->route('catalog.products', [
            'brand' => $brand,
            'model' => $model,
            ...($year ? ['year' => $year] : []),
        ]);
    }
}
