<?php

namespace App\Console\Commands;

use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Services\PriceList\NameParser;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Загрузка прайса в стейджинг с разбором наименований.
 * Каталог этой командой не трогается — только таблицы price_import_*.
 * Перенос в products/offers делает отдельная команда, уже по проверенным данным.
 */
class ImportPriceList extends Command
{
    protected $signature = 'price:import
        {file : Путь к файлу прайса (xlsx)}
        {--sheet= : Имя листа, по умолчанию первый}
        {--limit= : Обработать только первые N строк, для проверки}
        {--source= : Пометка об источнике (например «1С 02.08.26»)}';

    protected $description = 'Загрузить прайс-лист в стейджинг и разобрать наименования';

    /** Колонки файла: Наименование, Ед.изм, Картинка, Оптовая цена, Валюта, Артикул. */
    private const COL_NAME = 0;

    private const COL_UNIT = 1;

    private const COL_PRICE = 3;

    private const COL_ARTICLE = 5;

    private const CHUNK = 100;

    public function handle(NameParser $parser): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $run = PriceImportRun::create([
            'filename' => basename($path),
            'file_hash' => hash_file('sha256', $path),
            'source' => $this->option('source'),
            'status' => 'parsing',
            'started_at' => now(),
        ]);

        $this->info("Импорт #{$run->id}: ".basename($path));

        $stats = [
            'dialect' => [],
            'status' => [],
            'glass_type' => [],
            'brand' => 0,
            'model' => 0,
            'year' => 0,
            'body' => 0,
            'eurocode' => 0,
            'price' => 0,
            'co_models' => 0,
        ];

        $buffer = [];
        $rowNo = 0;
        $total = 0;

        $reader = new Reader;
        $reader->open($path);

        $bar = $this->output->createProgressBar();
        $bar->start();

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($this->option('sheet') && $sheet->getName() !== $this->option('sheet')) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNo++;

                    if ($rowNo === 1) {
                        continue;   // шапка
                    }

                    $cells = $row->toArray();
                    $name = trim((string) ($cells[self::COL_NAME] ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $parsed = $parser->parse($name);
                    $price = $this->parsePrice($cells[self::COL_PRICE] ?? null);

                    $buffer[] = [
                        'price_import_run_id' => $run->id,
                        'row_no' => $rowNo,
                        'raw_name' => $name,
                        'raw_unit' => trim((string) ($cells[self::COL_UNIT] ?? '')) ?: null,
                        'raw_price' => is_scalar($cells[self::COL_PRICE] ?? null)
                            ? (string) $cells[self::COL_PRICE]
                            : null,
                        'raw_article' => trim((string) ($cells[self::COL_ARTICLE] ?? '')) ?: null,

                        'dialect' => $parsed->dialect,
                        'supplier_name' => $parsed->supplier,
                        'brand_name' => $parsed->brand,
                        'model_name' => $parsed->model,
                        'glass_type' => $parsed->glassType?->value,
                        'position' => $parsed->position,
                        'year_from' => $parsed->yearFrom,
                        'year_to' => $parsed->yearTo,
                        'body_type' => $parsed->bodyType,
                        'doors' => $parsed->doors,
                        'eurocode' => $parsed->eurocode,
                        'internal_code' => $parsed->internalCode,
                        'price' => $price,
                        'options' => json_encode($parsed->options, JSON_UNESCAPED_UNICODE),
                        'co_models' => $parsed->coModels
                            ? json_encode($parsed->coModels, JSON_UNESCAPED_UNICODE)
                            : null,
                        'issues' => $parsed->issues
                            ? json_encode($parsed->issues, JSON_UNESCAPED_UNICODE)
                            : null,
                        'status' => $parsed->status(),
                        'confidence' => $parsed->confidence(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $this->collectStats($stats, $parsed, $price);
                    $total++;

                    if (count($buffer) >= self::CHUNK) {
                        PriceImportRow::insert($buffer);
                        $buffer = [];
                        $bar->advance(self::CHUNK);
                    }

                    if ($limit && $total >= $limit) {
                        break 2;
                    }
                }
            }
        } finally {
            if ($buffer) {
                PriceImportRow::insert($buffer);
                $bar->advance(count($buffer));
            }

            $reader->close();
            $bar->finish();
            $this->newLine(2);
        }

        $counts = PriceImportRow::where('price_import_run_id', $run->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $run->update([
            'status' => 'parsed',
            'rows_total' => $total,
            'rows_parsed' => (int) ($counts['parsed'] ?? 0),
            'rows_review' => (int) ($counts['review'] ?? 0),
            'rows_failed' => (int) ($counts['failed'] ?? 0),
            'stats' => $stats,
            'finished_at' => now(),
        ]);

        $this->report($run, $stats, $total);

        return self::SUCCESS;
    }

    private function parsePrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^\d.,]/u', '', (string) $value) ?? '';
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function collectStats(array &$stats, $parsed, ?float $price): void
    {
        $dialect = $parsed->dialect ?? 'unknown';
        $stats['dialect'][$dialect] = ($stats['dialect'][$dialect] ?? 0) + 1;

        $status = $parsed->status();
        $stats['status'][$status] = ($stats['status'][$status] ?? 0) + 1;

        $type = $parsed->glassType?->value ?? 'не распознан';
        $stats['glass_type'][$type] = ($stats['glass_type'][$type] ?? 0) + 1;

        $parsed->brand and $stats['brand']++;
        $parsed->model and $stats['model']++;
        ($parsed->yearFrom || $parsed->yearTo) and $stats['year']++;
        $parsed->bodyType and $stats['body']++;
        $parsed->eurocode and $stats['eurocode']++;
        $parsed->coModels and $stats['co_models']++;
        $price !== null and $stats['price']++;
    }

    private function report(PriceImportRun $run, array $stats, int $total): void
    {
        $pct = fn (int $n) => $total ? round($n / $total * 100, 1).'%' : '—';

        $this->info("Обработано строк: {$total}");
        $this->newLine();

        $this->line('<comment>Распознавание полей</comment>');
        $this->table(['Поле', 'Строк', 'Доля'], [
            ['Марка',        $stats['brand'],     $pct($stats['brand'])],
            ['Модель',       $stats['model'],     $pct($stats['model'])],
            ['Год',          $stats['year'],      $pct($stats['year'])],
            ['Кузов',        $stats['body'],      $pct($stats['body'])],
            ['Еврокод',      $stats['eurocode'],  $pct($stats['eurocode'])],
            ['Цена',         $stats['price'],     $pct($stats['price'])],
            ['Неск. машин',  $stats['co_models'], $pct($stats['co_models'])],
        ]);

        $this->line('<comment>Тип стекла</comment>');
        arsort($stats['glass_type']);
        $this->table(
            ['Тип', 'Строк', 'Доля'],
            collect($stats['glass_type'])->map(fn ($n, $k) => [$k, $n, $pct($n)])->values()->all()
        );

        $this->line('<comment>Диалект / поставщик</comment>');
        arsort($stats['dialect']);
        $this->table(
            ['Диалект', 'Строк', 'Доля'],
            collect($stats['dialect'])->map(fn ($n, $k) => [$k, $n, $pct($n)])->values()->all()
        );

        $this->line('<comment>Итог</comment>');
        $this->table(['Статус', 'Строк', 'Доля'], [
            ['Разобрано автоматически', $run->rows_parsed, $pct($run->rows_parsed)],
            ['На ручную проверку',      $run->rows_review, $pct($run->rows_review)],
        ]);

        $this->newLine();
        $this->line("Строки на проверку:  <info>php artisan price:review {$run->id}</info>");
    }
}
