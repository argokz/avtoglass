<?php

namespace App\Console\Commands;

use App\Catalog\ModelNormalizer;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Выправляет раскладку в названиях товаров: «СLK-Class» кириллической С,
 * «Ford KА», «Honda НR/V». Покупатель ищет «CLK» латиницей и такой товар
 * не находит — карточка есть, а продажи нет.
 *
 * Отдельная команда, а не пересборка каталога: сборка создаёт карточки
 * заново, теряя фотографии, остатки, резервы и позиции заказов.
 */
class FixNameLayout extends Command
{
    protected $signature = 'catalog:fix-layout {--apply : записать изменения, иначе только показать}';

    protected $description = 'Меняет кириллические буквы, набранные вместо латинских, в названиях товаров';

    public function handle(ModelNormalizer $normalizer): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];

        Product::query()->orderBy('id')->chunkById(500, function ($products) use ($normalizer, &$rows) {
            foreach ($products as $product) {
                $fixed = $normalizer->fixLayout($product->name);

                if ($fixed !== $product->name) {
                    $rows[] = [$product, $fixed];
                }
            }
        });

        if ($rows === []) {
            $this->info('Названий с перепутанной раскладкой нет.');

            return self::SUCCESS;
        }

        $this->table(
            ['Было', 'Станет'],
            array_map(fn ($row) => [$row[0]->name, $row[1]], $rows)
        );

        if (! $apply) {
            $this->warn('Найдено '.count($rows).'. Запустите с --apply, чтобы записать.');

            return self::SUCCESS;
        }

        foreach ($rows as [$product, $fixed]) {
            $product->forceFill(['name' => $fixed])->save();
        }

        $this->info('Исправлено названий: '.count($rows).'.');

        return self::SUCCESS;
    }
}
