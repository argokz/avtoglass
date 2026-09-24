<?php

namespace App\Console\Commands;

use App\Models\CarBrand;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Scraper\AutoGlassScraper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Комплексное наполнение каталога всеми видами медиа:
 * 1. Логотипы марок автомобилей (CarBrand).
 * 2. Логотипы производителей автостекол (Supplier).
 * 3. Чертежи и фото автостекол с размерами (Product).
 * Фото моделей качает catalog:scrape-internet --models, не этот генератор силуэтов.
 */
class PopulateCatalogMedia extends Command
{
    protected $signature = 'catalog:populate-all-media
        {--force : Перезаписать существующие изображения}';

    protected $description = 'Заполнить медиа для всех марок, моделей, поставщиков и автостёкол';

    public function handle(AutoGlassScraper $scraper): int
    {
        $this->info('🚀 Запуск комплексного наполнения медиа...');

        // 1. Наполнение брендов
        $this->populateBrands();

        // 2. Наполнение поставщиков автостекол
        $this->populateSuppliers();

        // Силуэты моделей в файлы не пишем: на витрине они и так рисуются,
        // а настоящие снимки качает catalog:scrape-internet --models.

        // 4. Наполнение автостёкол (чертежи и размеры)
        $this->populateProducts($scraper);

        $this->newLine();
        $this->info('✅ Все фото, логотипы и чертежи с размерами успешно сгенерированы и привязаны к базе данных!');

        return self::SUCCESS;
    }

    private function populateBrands(): void
    {
        $this->info('1/4. Генерация логотипов марок автомобилей...');
        $brands = CarBrand::all();
        $count = 0;

        foreach ($brands as $brand) {
            if (filled($brand->logo_path) && ! $this->option('force')) {
                continue;
            }

            $slug = Str::slug($brand->name);
            $filename = "brands/{$slug}.svg";

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
    <rect width="100" height="100" rx="20" fill="#0f172a" />
    <circle cx="50" cy="50" r="36" fill="none" stroke="#38bdf8" stroke-width="4" />
    <text x="50" y="56" fill="#ffffff" font-family="sans-serif" font-weight="900" font-size="16" text-anchor="middle">{$brand->name}</text>
</svg>
SVG;

            Storage::disk('public')->put($filename, $svg);
            $brand->update(['logo_path' => $filename]);
            $count++;
        }

        $this->line("  обновлено марок: {$count}");
    }

    private function populateSuppliers(): void
    {
        $this->info('2/4. Генерация логотипов производителей автостекол...');
        $suppliers = Supplier::all();
        $count = 0;

        foreach ($suppliers as $supplier) {
            if (filled($supplier->logo_path) && ! $this->option('force')) {
                continue;
            }

            $slug = Str::slug($supplier->name);
            $filename = "suppliers/{$slug}.svg";

            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 60" width="160" height="60">
    <rect width="160" height="60" rx="12" fill="#1e293b" />
    <text x="80" y="36" fill="#38bdf8" font-family="sans-serif" font-weight="bold" font-size="16" text-anchor="middle">{$supplier->name}</text>
</svg>
SVG;

            Storage::disk('public')->put($filename, $svg);
            $supplier->update(['logo_path' => $filename]);
            $count++;
        }

        $this->line("  обновлено поставщиков: {$count}");
    }

    private function populateProducts(AutoGlassScraper $scraper): void
    {
        $this->info('4/4. Скрапинг и наполнение автостёкол чертежами с размерами...');
        $products = Product::all();
        $res = $scraper->scrapeCollection($products, dryRun: false, delayMs: 0);

        $this->line("  обработано стёкол: {$res['scraped']}, сгенерировано чертежей: {$res['images']}, габаритов: {$res['dimensions']}");
    }
}
