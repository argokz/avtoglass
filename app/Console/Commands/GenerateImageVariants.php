<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ImageVariants;
use Illuminate\Console\Command;

/**
 * Нарезка WebP-вариантов для снимков товаров.
 *
 * Запускается после media:import и по расписанию: новые снимки без
 * вариантов доезжают до витрины как оригиналы, и LCP на карточке проседает.
 */
class GenerateImageVariants extends Command
{
    protected $signature = 'media:variants {--missing : Только снимки без вариантов}';

    protected $description = 'Сгенерировать WebP-варианты снимков товаров для srcset';

    public function handle(ImageVariants $variants): int
    {
        $made = 0;
        $processed = 0;

        Product::query()
            ->whereNotNull('image_path')
            ->select(['id', 'image_path'])
            ->chunkById(200, function ($chunk) use ($variants, &$made, &$processed) {
                foreach ($chunk as $product) {
                    $processed++;
                    $made += $variants->generate($product->image_path);
                }
            });

        $this->info("Обработано снимков: {$processed}, создано вариантов: {$made}");

        return self::SUCCESS;
    }
}
