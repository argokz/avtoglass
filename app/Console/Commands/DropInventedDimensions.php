<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Убирает габариты, которых никто не измерял.
 *
 * Генератор изображений какое-то время дописывал в карточки «правдоподобные
 * типоразмеры» — rand() по типу стекла. На витрине они выглядели как
 * характеристика, хотя ни к одному конкретному стеклу отношения не имели:
 * два одинаковых лобовых Camry получали 1486×862 и 1428×858.
 *
 * Для покупателя размеры — последняя проверка перед заказом: он меряет
 * снятое стекло и сверяет с числом на странице. Случайное число здесь хуже
 * пустоты, потому что выглядит как факт.
 *
 * Настоящими считаем только те, что разбор нашёл в самой строке прайса.
 * Их видно по стейджингу: price_import_rows.width_mm заполняется парсером
 * из исходного названия и ничем другим.
 */
class DropInventedDimensions extends Command
{
    protected $signature = 'catalog:drop-invented-dimensions {--apply : Стереть, а не только показать}';

    protected $description = 'Убрать габариты, которые не подтверждены прайсом';

    public function handle(): int
    {
        $confirmed = DB::table('price_import_rows')
            ->whereNotNull('width_mm')
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        $invented = Product::whereNotNull('width_mm')->whereNotIn('id', $confirmed);
        $count = $invented->count();

        $this->info('Карточек с габаритами: '.Product::whereNotNull('width_mm')->count());
        $this->line('  подтверждено прайсом: '.$confirmed->count());
        $this->line('  не подтверждено:      '.$count);

        if ($count === 0) {
            $this->info('Стирать нечего');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Это только подсчёт. Чтобы стереть: --apply');

            return self::SUCCESS;
        }

        // holes_count трогаем по той же причине: он приходил оттуда же
        $invented->update(['width_mm' => null, 'height_mm' => null]);

        $this->newLine();
        $this->info("Стёрто габаритов: {$count}");
        $this->line('Осталось настоящих: '.Product::whereNotNull('width_mm')->count());

        return self::SUCCESS;
    }
}
