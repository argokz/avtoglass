<?php

namespace App\Http\Controllers;

use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Подсказки в строке поиска: модели и карточки.
 *
 * Лёгкий отдельный запрос, а не полный поиск: он дёргается на каждую
 * паузу ввода, и тащить ради подсказки всю машинерию SearchOffers
 * с применимостью и резервами было бы расточительно.
 */
class SuggestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (mb_strlen($q) < 2) {
            return response()->json(['models' => [], 'products' => []]);
        }

        $term = '%'.$q.'%';

        $models = CarModel::query()
            ->where('products_count', '>', 0)
            ->where(fn ($query) => $query->where('name', 'ilike', $term)->orWhere('name_ru', 'ilike', $term))
            ->with('brand:id,name,slug')
            ->orderByDesc('products_count')
            ->limit(5)
            ->get(['id', 'name', 'slug', 'car_brand_id'])
            ->map(fn (CarModel $model) => [
                'label' => $model->brand->name.' '.$model->name,
                'url' => route('catalog.products', [$model->brand, $model]),
            ]);

        $products = Product::query()
            ->where('is_published', true)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', $term)
                ->orWhere('eurocode', 'ilike', $term)
                ->orWhere('oem_code', 'ilike', $term))
            ->orderByRaw('price_min IS NULL')
            ->orderBy('price_min')
            ->limit(6)
            ->get(['id', 'slug', 'name', 'eurocode', 'price_min'])
            ->map(fn (Product $product) => [
                'label' => $product->name,
                'detail' => $product->eurocode,
                'price' => $product->price_min !== null ? formatPrice($product->price_min).' ₸' : null,
                'url' => route('catalog.product', $product),
            ]);

        return response()->json(['models' => $models, 'products' => $products])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
