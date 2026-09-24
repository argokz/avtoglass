<?php

namespace App\Services\PriceList;

use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Загрузка Excel-прайса в стейджинг.
 *
 * Каталог отсюда не трогается: сначала строки разбираются, потом
 * отдельная команда catalog:build склеивает их в товары и офферы.
 * Один и тот же код дергают и консоль, и страница в CRM.
 */
class PriceListImporter
{
    private const COL_NAME = 0;

    private const COL_UNIT = 1;

    private const COL_PRICE = 3;

    private const COL_ARTICLE = 5;

    private const CHUNK = 100;

    public function __construct(private readonly NameParser $parser) {}

    /**
     * @param  callable(int):void|null  $onProgress  сколько строк только что записали
     */
    public function import(
        string $path,
        ?string $source = null,
        ?string $sheet = null,
        ?int $limit = null,
        ?callable $onProgress = null,
    ): PriceImportRun {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Файл не найден: {$path}");
        }

        $run = PriceImportRun::create([
            'filename' => basename($path),
            'file_hash' => hash_file('sha256', $path),
            'source' => $source,
            'status' => 'parsing',
            'started_at' => now(),
        ]);

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

        try {
            foreach ($reader->getSheetIterator() as $currentSheet) {
                if ($sheet && $currentSheet->getName() !== $sheet) {
                    continue;
                }

                foreach ($currentSheet->getRowIterator() as $row) {
                    $rowNo++;

                    if ($rowNo === 1) {
                        continue;
                    }

                    $cells = $row->toArray();
                    $name = trim((string) ($cells[self::COL_NAME] ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $parsed = $this->parser->parse($name);
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
                        'width_mm' => $parsed->widthMm,
                        'height_mm' => $parsed->heightMm,
                        'holes_count' => $parsed->holesCount,
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
                        $onProgress && $onProgress(count($buffer));
                        $buffer = [];
                    }

                    if ($limit && $total >= $limit) {
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            $reader->close();
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw new RuntimeException('Не удалось разобрать прайс: '.$e->getMessage(), 0, $e);
        }

        if ($buffer) {
            PriceImportRow::insert($buffer);
            $onProgress && $onProgress(count($buffer));
        }

        $reader->close();

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

        return $run->fresh();
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

    private function collectStats(array &$stats, ParsedName $parsed, ?float $price): void
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
}
