<?php

namespace App\Console\Commands;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Генерация sitemap.xml для поисковых систем (Google, Yandex).
 * Включает главную, каталог марок, модели и все опубликованные товары.
 */
class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Сгенерировать sitemap.xml со всеми страницами каталога и товаров';

    public function handle(): int
    {
        $this->info('Генерация sitemap.xml...');

        $baseUrl = rtrim(config('app.url', 'https://avtoglass.kz'), '/');
        $now = date('c');

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // 1. Главные статические страницы
        $xml[] = "  <url><loc>{$baseUrl}</loc><lastmod>{$now}</lastmod><changefreq>daily</changefreq><priority>1.0</priority></url>";
        $xml[] = "  <url><loc>{$baseUrl}/catalog</loc><lastmod>{$now}</lastmod><changefreq>daily</changefreq><priority>0.9</priority></url>";
        $xml[] = "  <url><loc>{$baseUrl}/vin</loc><lastmod>{$now}</lastmod><changefreq>monthly</changefreq><priority>0.8</priority></url>";

        // 2. Страницы марок
        $brands = CarBrand::query()->where('models_count', '>', 0)->get(['id', 'slug', 'updated_at']);
        foreach ($brands as $brand) {
            $lastmod = ($brand->updated_at ?: now())->toIso8601String();
            $xml[] = "  <url><loc>{$baseUrl}/catalog/{$brand->slug}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>";
        }

        // 3. Страницы моделей
        $models = CarModel::with('brand')->where('products_count', '>', 0)->get(['id', 'car_brand_id', 'slug', 'updated_at']);
        foreach ($models as $model) {
            if ($model->brand) {
                $lastmod = ($model->updated_at ?: now())->toIso8601String();
                $xml[] = "  <url><loc>{$baseUrl}/catalog/{$model->brand->slug}/{$model->slug}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.7</priority></url>";
            }
        }

        // 4. Карточки товаров
        Product::query()
            ->where('is_published', true)
            ->select(['id', 'slug', 'updated_at'])
            ->chunkById(500, function ($products) use (&$xml, $baseUrl) {
                foreach ($products as $product) {
                    $lastmod = ($product->updated_at ?: now())->toIso8601String();
                    $xml[] = "  <url><loc>{$baseUrl}/tovar/{$product->slug}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>";
                }
            });

        $xml[] = '</urlset>';

        file_put_contents(public_path('sitemap.xml'), implode("\n", $xml));

        $totalUrls = count($xml) - 2;
        $this->info("sitemap.xml успешно сгенерирован! Всего URL: {$totalUrls}");

        return self::SUCCESS;
    }
}
