<?php

namespace App\Http\Controllers;

use App\Models\CarBrand;
use App\Models\Product;
use Illuminate\Http\Response;

/**
 * Карта сайта для поисковиков.
 *
 * Только витрина: заявки, корзина и админка в индекс не должны попадать.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = [
            ['loc' => route('home'), 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => route('catalog.brands'), 'changefreq' => 'daily', 'priority' => '0.9'],
            ['loc' => route('order.form'), 'changefreq' => 'monthly', 'priority' => '0.6'],
        ];

        if (settings()->enabled('vin_lookup')) {
            $urls[] = ['loc' => route('vin.form'), 'changefreq' => 'monthly', 'priority' => '0.5'];
        }

        if (settings()->enabled('booking')) {
            $urls[] = ['loc' => route('booking.form'), 'changefreq' => 'weekly', 'priority' => '0.6'];
        }

        foreach (CarBrand::query()->where('models_count', '>', 0)->with(['models' => fn ($q) => $q->where('products_count', '>', 0)])->orderBy('name')->get() as $brand) {
            $urls[] = ['loc' => route('catalog.models', $brand), 'changefreq' => 'weekly', 'priority' => '0.7'];

            foreach ($brand->models as $model) {
                $urls[] = ['loc' => route('catalog.products', [$brand, $model]), 'changefreq' => 'weekly', 'priority' => '0.7'];
            }
        }

        foreach (Product::query()->where('is_published', true)->orderBy('id')->cursor() as $product) {
            $urls[] = ['loc' => route('catalog.product', $product), 'changefreq' => 'weekly', 'priority' => '0.8'];
        }

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
