<?php

namespace App\Wholesale\Import;

use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/** Pure file -> review rows. Never writes to the catalog, draft, order or inventory. */
final class PurchaseFileReader
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_ROWS = 1000;

    public const HEADERS = [
        'article' => ['article', 'code', 'артикул', 'еврокод', 'код', 'еурокод', 'тауаркоды'],
        'quantity' => ['quantity', 'qty', 'количество', 'колво', 'саны', 'сан', 'мөлшері'],
        'manufacturer' => ['manufacturer', 'brand', 'производитель', 'бренд', 'өндіруші'],
        'comment' => ['comment', 'комментарий', 'примечание', 'түсініктеме', 'ескерту'],
    ];

    public function read(string $path, string $extension): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new PurchaseImportException('file_size');
        }
        try {
            return match (strtolower($extension)) {
                'csv' => $this->csv($path),
                'xlsx' => $this->xlsx($path),
                default => throw new PurchaseImportException('file_type'),
            };
        } catch (PurchaseImportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PurchaseImportException('file_invalid');
        }
    }

    private function csv(string $path): array
    {
        $stream = fopen($path, 'rb');
        try {
            $first = '';
            while (($line = fgets($stream, 32769)) !== false) {
                if (strlen($line) >= 32768) {
                    throw new PurchaseImportException('file_cell_length');
                }
                if (trim($line, "\xEF\xBB\xBF \t\r\n") !== '') {
                    $first = ltrim($line, "\xEF\xBB\xBF");
                    break;
                }
            }
            $delimiter = count(str_getcsv($first, ';', '"', '')) >= count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
            rewind($stream);
            $map = null;
            $rows = [];
            $nextLine = 1;
            while (! feof($stream)) {
                $before = ftell($stream);
                $values = fgetcsv($stream, 0, $delimiter, '"', '');
                if ($values === false) {
                    break;
                }
                if (ftell($stream) - $before > 32768 || count($values) > 64) {
                    throw new PurchaseImportException('file_cell_length');
                }
                $after = ftell($stream);
                fseek($stream, $before);
                $record = fread($stream, $after - $before);
                $number = $nextLine;
                $nextLine += max(1, preg_match_all('/\r\n|\n|\r/', $record));
                $values = array_map(fn ($value) => (string) ($value ?? ''), $values);
                foreach ($values as $value) {
                    if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                        throw new PurchaseImportException('file_encoding');
                    }
                }
                if (! array_filter($values, fn ($value) => trim($value) !== '')) {
                    continue;
                }
                if ($map === null) {
                    $map = $this->headers($values);

                    continue;
                }
                $rows[$number] = $this->row($values, $map, []);
                $this->checkRowCount($rows);
            }
            $this->checkContent($map, $rows);

            return ['rows' => $rows, 'sheet' => null];
        } finally {
            fclose($stream);
        }
    }

    private function xlsx(string $path): array
    {
        (new XlsxArchiveGuard)->check($path);
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        try {
            $reader->open($path);
            $map = null;
            $rows = [];
            $sheetName = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = $sheet->getName();
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = [];
                    $formulas = [];
                    if ($row->getNumCells() > 64) {
                        throw new PurchaseImportException('file_cell_length');
                    }
                    foreach ($row->getCells() as $index => $cell) {
                        $value = $cell->getValue();
                        $values[$index] = is_string($value) || is_numeric($value) ? (string) $value : '';
                        if ($cell instanceof FormulaCell) {
                            $formulas[] = $index;
                        }
                    }
                    if (! array_filter($values, fn ($value) => trim($value) !== '')) {
                        continue;
                    }
                    if ($map === null) {
                        if ($formulas) {
                            throw new PurchaseImportException('file_headers');
                        }
                        $map = $this->headers($values);

                        continue;
                    }
                    $rows[$number] = $this->row($values, $map, $formulas);
                    $this->checkRowCount($rows);
                }
                break; // The UI explicitly identifies the first worksheet being processed.
            }
            $this->checkContent($map, $rows);

            return ['rows' => $rows, 'sheet' => $sheetName];
        } finally {
            $reader->close();
        }
    }

    private function headers(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            $normalized = preg_replace('/[\s_\-]+/u', '', mb_strtolower(trim(ltrim($value, "\xEF\xBB\xBF"))));
            if ($normalized === '') {
                continue;
            }
            $matched = false;
            foreach (self::HEADERS as $field => $aliases) {
                if (in_array($normalized, $aliases, true)) {
                    if (isset($map[$field])) {
                        throw new PurchaseImportException('file_headers');
                    }
                    $map[$field] = $index;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                throw new PurchaseImportException('file_headers');
            }
        }
        if (! isset($map['article'], $map['quantity'])) {
            throw new PurchaseImportException('file_headers');
        }

        return $map;
    }

    private function row(array $values, array $map, array $formulas): array
    {
        $row = ['article' => '', 'quantity' => '', 'manufacturer' => '', 'comment' => '', 'issue' => null];
        foreach ($map as $field => $index) {
            $row[$field] = trim($values[$index] ?? '');
        }
        if (array_intersect($formulas, array_values($map))) {
            $row['issue'] = 'formula';
        }
        foreach (['article' => 100, 'quantity' => 30, 'manufacturer' => 100, 'comment' => 1000] as $field => $max) {
            if (mb_strlen($row[$field]) > $max) {
                throw new PurchaseImportException('file_cell_length');
            }
        }
        foreach ($values as $index => $value) {
            if (! in_array($index, $map, true) && trim($value) !== '') {
                $row['issue'] = 'format';
            }
        }

        return $row;
    }

    private function checkRowCount(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new PurchaseImportException('file_rows');
        }
    }

    private function checkContent(?array $map, array $rows): void
    {
        if ($map === null || $rows === []) {
            throw new PurchaseImportException('file_empty');
        }
    }
}
