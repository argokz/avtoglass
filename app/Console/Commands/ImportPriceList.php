<?php

namespace App\Console\Commands;

use App\Models\PriceImportRun;
use App\Services\PriceList\PriceListImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Загрузка прайса в стейджинг с разбором наименований.
 * Каталог этой командой не трогается — только таблицы price_import_*.
 * Перенос в products/offers делает catalog:build, уже по проверенным данным.
 */
class ImportPriceList extends Command
{
    protected $signature = 'price:import
        {file : Путь к файлу прайса (xlsx)}
        {--sheet= : Имя листа, по умолчанию первый}
        {--limit= : Обработать только первые N строк, для проверки}
        {--source= : Пометка об источнике (например «1С 02.08.26»)}';

    protected $description = 'Загрузить прайс-лист в стейджинг и разобрать наименования';

    public function handle(PriceListImporter $importer): int
    {
        $path = $this->argument('file');

        $bar = $this->output->createProgressBar();
        $bar->start();

        try {
            $run = $importer->import(
                path: $path,
                source: $this->option('source'),
                sheet: $this->option('sheet') ?: null,
                limit: $this->option('limit') ? (int) $this->option('limit') : null,
                onProgress: fn (int $n) => $bar->advance($n),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Импорт #{$run->id}: {$run->filename}");
        $this->report($run);

        return self::SUCCESS;
    }

    private function report(PriceImportRun $run): void
    {
        $stats = $run->stats ?? [];
        $total = (int) $run->rows_total;
        $pct = fn (int $n) => $total ? round($n / $total * 100, 1).'%' : '—';

        $this->info("Обработано строк: {$total}");
        $this->newLine();

        $this->line('<comment>Распознавание полей</comment>');
        $this->table(['Поле', 'Строк', 'Доля'], [
            ['Марка', $stats['brand'] ?? 0, $pct((int) ($stats['brand'] ?? 0))],
            ['Модель', $stats['model'] ?? 0, $pct((int) ($stats['model'] ?? 0))],
            ['Год', $stats['year'] ?? 0, $pct((int) ($stats['year'] ?? 0))],
            ['Кузов', $stats['body'] ?? 0, $pct((int) ($stats['body'] ?? 0))],
            ['Еврокод', $stats['eurocode'] ?? 0, $pct((int) ($stats['eurocode'] ?? 0))],
            ['Цена', $stats['price'] ?? 0, $pct((int) ($stats['price'] ?? 0))],
            ['Неск. машин', $stats['co_models'] ?? 0, $pct((int) ($stats['co_models'] ?? 0))],
        ]);

        if (! empty($stats['glass_type']) && is_array($stats['glass_type'])) {
            $this->line('<comment>Тип стекла</comment>');
            arsort($stats['glass_type']);
            $this->table(
                ['Тип', 'Строк', 'Доля'],
                collect($stats['glass_type'])->map(fn ($n, $k) => [$k, $n, $pct((int) $n)])->values()->all()
            );
        }

        if (! empty($stats['dialect']) && is_array($stats['dialect'])) {
            $this->line('<comment>Диалект / поставщик</comment>');
            arsort($stats['dialect']);
            $this->table(
                ['Диалект', 'Строк', 'Доля'],
                collect($stats['dialect'])->map(fn ($n, $k) => [$k, $n, $pct((int) $n)])->values()->all()
            );
        }

        $this->line('<comment>Итог</comment>');
        $this->table(['Статус', 'Строк', 'Доля'], [
            ['Разобрано автоматически', $run->rows_parsed, $pct((int) $run->rows_parsed)],
            ['На ручную проверку', $run->rows_review, $pct((int) $run->rows_review)],
        ]);

        $this->newLine();
        $this->line("Строки на проверку:  <info>php artisan price:review {$run->id}</info>");
        $this->line("Собрать каталог:     <info>php artisan catalog:build {$run->id}</info>");
    }
}
