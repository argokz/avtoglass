<?php

namespace App\Console\Commands;

use App\Catalog\DimensionsBackfill;
use App\Catalog\FitmentYears;
use App\Catalog\SupplierCountries;
use Illuminate\Console\Command;

/**
 * Дозаполняет каталог тем, что уже есть в данных, но не доехало до карточек.
 *
 * Все три шага идемпотентны и заполняют только пустое, поэтому команду
 * гоняют после каждой сборки каталога и просто так, когда пополнили
 * справочники руками.
 */
class EnrichCatalog extends Command
{
    protected $signature = 'catalog:enrich';

    protected $description = 'Достроить годы применимости, габариты и страны производства';

    public function handle(
        FitmentYears $years,
        DimensionsBackfill $dimensions,
        SupplierCountries $countries,
    ): int {
        $this->info('Годы применимости по поколению...');
        $this->line('  уточнено: '.$years->backfill());

        $this->info('Габариты из названий в прайсе...');
        $sizes = $dimensions->run();
        $this->line("  строк прайса: {$sizes['rows']}, карточек: {$sizes['products']}");

        $this->info('Страны производства...');
        $result = $countries->fill();
        $this->line('  заполнено поставщиков: '.$result['filled']);

        if ($result['unknown'] !== []) {
            $this->newLine();
            $this->warn('Страну не знаю наверняка, поставьте руками в карточке поставщика:');
            $this->line('  '.implode(', ', $result['unknown']));
        }

        return self::SUCCESS;
    }
}
