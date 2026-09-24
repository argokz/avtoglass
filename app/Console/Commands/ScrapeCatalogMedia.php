<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Scraper\AutoGlassScraper;
use Illuminate\Console\Command;

/**
 * Команда для скрапинга и автозаполнения карточек автостекол:
 * 1. Генерация/скачивание схем и фото с точными размерами.
 * 2. Заполнение полей width_mm, height_mm, holes_count, tint, description.
 */
class ScrapeCatalogMedia extends Command
{
    protected $signature = 'catalog:scrape-media
        {--limit=0 : Максимальное количество товаров (0 = все)}
        {--only-missing : Обрабатывать только товары без изображений или габаритов}
        {--force : Перезаписывать существующие изображения}
        {--dry-run : Только показать результат без сохранения в БД}';

    protected $description = 'Скрапинг и автозаполнение карточек автостекол фото, габаритами и параметрами';

    public function handle(AutoGlassScraper $scraper): int
    {
        $limit = (int) $this->option('limit');
        $onlyMissing = $this->option('only-missing');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $query = Product::query();

        if ($onlyMissing && ! $force) {
            $query->where(function ($q) {
                $q->whereNull('image_path')
                    ->orWhereNull('width_mm')
                    ->orWhereNull('height_mm');
            });
        }

        $totalCount = $query->count();

        if ($totalCount === 0) {
            $this->info('Нет подходящих товаров для наполнения.');

            return self::SUCCESS;
        }

        if ($limit > 0) {
            $query->limit($limit);
            $this->info("Обработка {$limit} из {$totalCount} товаров...");
        } else {
            $this->info("Начало обработки всех {$totalCount} товаров...");
        }

        if ($dryRun) {
            $this->warn('Режим DRY-RUN: данные не будут записаны в базу.');
        }

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $totalCount) : $totalCount);
        $bar->start();

        $stats = [
            'total' => 0,
            'images' => 0,
            'dimensions' => 0,
        ];

        $query->chunkById(100, function ($products) use ($scraper, $dryRun, $force, $bar, &$stats) {
            foreach ($products as $product) {
                $stats['total']++;

                $data = $scraper->fetchDataForProduct($product);

                if ($data !== null && ! $dryRun) {
                    if ($force) {
                        $product->image_path = null;
                    }

                    $updated = $scraper->applyDataToProduct($product, $data);
                    if ($updated['image']) {
                        $stats['images']++;
                    }
                    if ($updated['dimensions']) {
                        $stats['dimensions']++;
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('Результаты скрапинга и наполнения:');
        $this->line("  Всего обработано товаров: {$stats['total']}");
        if (! $dryRun) {
            $this->line("  Добавлено фото/схем:      {$stats['images']}");
            $this->line("  Заполнено габаритов:      {$stats['dimensions']}");
        }

        return self::SUCCESS;
    }
}
