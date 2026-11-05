<?php

namespace App\Http\Controllers;

use App\Catalog\ProductFilter;
use App\Catalog\StorefrontSearch;
use App\Catalog\Vin;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use App\Models\SearchQuery;
use App\Models\Service;
use App\Support\StorefrontCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(StorefrontCache $cache): View
    {
        // В кэш — только id и счётчики: модели собираем заново, иначе
        // database-драйвер отдаёт не объекты, и главная падает.
        $cached = $cache->remember('home:ids', fn () => [
            'brand_ids' => CarBrand::where('models_count', '>', 0)->orderBy('name')->pluck('id')->all(),
            'popular_ids' => CarBrand::where('models_count', '>', 0)
                ->orderByDesc('models_count')
                ->limit(12)
                ->pluck('id')
                ->all(),
            'popular_model_ids' => CarModel::where('products_count', '>', 0)
                ->orderByDesc('products_count')
                ->limit(8)
                ->pluck('id')
                ->all(),
            'service_ids' => Service::where('is_active', true)->orderBy('sort')->pluck('id')->all(),
            'stats' => [
                'products' => Product::where('is_published', true)->count(),
                'brands' => CarBrand::where('models_count', '>', 0)->count(),
                'models' => CarModel::where('products_count', '>', 0)->count(),
            ],
        ]);

        $data = [
            'brands' => $cache->hydrate(CarBrand::class, $cached['brand_ids'] ?? []),
            'popular' => $cache->hydrate(CarBrand::class, $cached['popular_ids'] ?? []),
            'popularModels' => $cache->hydrate(CarModel::class, $cached['popular_model_ids'] ?? [], ['brand']),
            'services' => $cache->hydrate(Service::class, $cached['service_ids'] ?? []),
            'stats' => $cached['stats'] ?? ['products' => 0, 'brands' => 0, 'models' => 0],
        ];

        // Персональный блок в общий кэш не кладём: машина своя у каждого.
        // Id подходящих стёкол — данные каталога, их можно помнить коротко.
        $data['myCar'] = $this->myCar($cache);

        return view('home', $data);
    }

    /**
     * «Для вашего авто»: стёкла для машины, которую человек уже выбирал.
     *
     * @return array{label:string,url:string,products:Collection}|null
     */
    private function myCar(StorefrontCache $cache): ?array
    {
        $car = session('car');

        if (! is_array($car) || empty($car['model_id'])) {
            return null;
        }

        $model = CarModel::with('brand')->find($car['model_id']);

        if ($model === null) {
            return null;
        }

        $year = ProductFilter::sanitizeYear($car['year'] ?? null);
        $productIds = $cache->remember(
            'mycar:ids:'.$model->id.':'.($year ?? 'any'),
            fn () => Product::query()
                ->where('is_published', true)
                ->whereHas('fitments', function ($q) use ($model, $year) {
                    $q->where('car_model_id', $model->id);

                    if ($year !== null) {
                        $q->where(fn ($y) => $y->whereNull('year_from')->orWhere('year_from', '<=', $year))
                            ->where(fn ($y) => $y->whereNull('year_to')->orWhere('year_to', '>=', $year));
                    }
                })
                // Отечественное — первым, как и в общей выдаче.
                ->orderByRaw("case when products.origin_country = 'KZ' then 0 else 1 end")
                ->orderByRaw('price_min IS NULL')
                ->orderBy('price_min')
                ->limit(8)
                ->pluck('id')
                ->all(),
            120,
        );

        $products = $cache->hydrate(Product::class, $productIds);
        $products->loadCount('offers');

        if ($products->isEmpty()) {
            return null;
        }

        return [
            'label' => trim($model->brand->name.' '.$model->name.($year ? ', '.$year : '')),
            'url' => route('catalog.products', array_filter([$model->brand, $model, 'year' => $year])),
            'products' => $products,
        ];
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
    public function search(Request $request, StorefrontSearch $search): View|RedirectResponse
    {
        $raw = trim((string) $request->query('q'));

        /*
         * VIN в общей строке поиска — сразу в подбор по VIN: искать
         * семнадцатизначный код по названиям стёкол бессмысленно,
         * а человеку нужна его машина.
         */
        $vin = Vin::parse($raw);
        if ($vin !== null && settings()->enabled('vin_lookup')) {
            session(['vin' => $vin->value]);

            return redirect()->route('vin.form');
        }

        $hits = $search->find($request);

        // Журнал запросов: нули в нём — список пополнения каталога.
        // Поиск не должен падать из-за телеметрии.
        try {
            SearchQuery::record(
                $hits->query ?? $raw,
                $hits->products->total() + $hits->models->count() + $hits->brands->count(),
            );
        } catch (\Throwable) {
            // таблица может быть ещё не мигрирована — поиску это не мешает
        }

        return view('catalog.search', [
            'q' => $hits->query ?? $raw,
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
        if ($model->car_brand_id !== $brand->id) {
            throw ValidationException::withMessages([
                'model' => 'Выберите модель указанной марки.',
            ]);
        }

        // Запоминаем машину: на главной появится блок «Для вашего авто».
        session(['car' => [
            'brand_id' => $brand->id,
            'model_id' => $model->id,
            'year' => $year,
        ]]);

        return redirect()->route('catalog.products', [
            'brand' => $brand,
            'model' => $model,
            ...($year ? ['year' => $year] : []),
        ]);
    }
}
