<?php

namespace App\Console\Commands;

use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use Illuminate\Console\Command;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Строки, которые парсер не разобрал уверенно.
 * Выгружаются в xlsx, потому что размечать их будет контент-менеджер в Excel,
 * а не через консоль. Обратно правки принимает price:corrections.
 */
class ReviewPriceList extends Command
{
    protected $signature = 'price:review
        {run? : ID импорта, по умолчанию последний}
        {--export= : Выгрузить в xlsx по указанному пути}
        {--limit=40 : Сколько строк показать в консоли}';

    protected $description = 'Показать или выгрузить строки прайса, требующие ручной разметки';

    private const EXPORT_HEADER = [
        'ID', 'Строка прайса', 'Уверенность', 'Замечания',
        'Диалект', 'Поставщик', 'Марка', 'Модель', 'Тип стекла',
        'Год с', 'Год по', 'Кузов', 'Двери', 'Еврокод', 'Цена',
    ];

    public function handle(): int
    {
        $run = $this->argument('run')
            ? PriceImportRun::find($this->argument('run'))
            : PriceImportRun::latest('id')->first();

        if (! $run) {
            $this->error('Импорт не найден.');

            return self::FAILURE;
        }

        $query = PriceImportRow::where('price_import_run_id', $run->id)
            ->whereIn('status', ['review', 'failed'])
            ->orderBy('confidence')
            ->orderBy('row_no');

        $total = $query->count();

        if ($total === 0) {
            $this->info("Импорт #{$run->id}: ручная разметка не требуется.");

            return self::SUCCESS;
        }

        $this->info("Импорт #{$run->id}: {$total} строк требуют проверки");
        $this->newLine();

        if ($path = $this->option('export')) {
            $this->export($query, $path, $total);

            return self::SUCCESS;
        }

        $this->line('<comment>Причины</comment>');
        $reasons = [];
        foreach ($query->clone()->pluck('issues') as $list) {
            foreach ((array) $list as $issue) {
                $reasons[$issue] = ($reasons[$issue] ?? 0) + 1;
            }
        }
        arsort($reasons);
        $this->table(
            ['Причина', 'Строк'],
            collect($reasons)->map(fn ($c, $r) => [$r, $c])->values()->all()
        );

        $this->line('<comment>Строки</comment>');
        $rows = $query->limit((int) $this->option('limit'))->get();

        $this->table(
            ['ID', 'Увер.', 'Марка', 'Тип', 'Строка прайса'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->confidence,
                mb_substr((string) $r->brand_name, 0, 12) ?: '—',
                $r->glass_type ?: '—',
                mb_substr($r->raw_name, 0, 62),
            ])->all()
        );

        if ($total > (int) $this->option('limit')) {
            $this->line('Показаны первые '.$this->option('limit')." из {$total}.");
        }

        $this->newLine();
        $this->line('Выгрузить всё в Excel для разметки:');
        $this->line("  <info>php artisan price:review {$run->id} --export=storage/app/review-{$run->id}.xlsx</info>");

        return self::SUCCESS;
    }

    /**
     * Строка выгрузки, где текст остаётся текстом.
     *
     * Row::fromValues превращает строку, начинающуюся с «=», в формулу Excel,
     * а наименование пришло из чужого файла. Формула из прайса не должна
     * выполняться на компьютере менеджера, который откроет выгрузку.
     */
    public static function textRow(array $values): Row
    {
        return new Row(array_map(
            fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value),
            $values,
        ));
    }

    private function export($query, string $path, int $total): void
    {
        if (! str_ends_with(mb_strtolower($path), '.xlsx')) {
            $path .= '.xlsx';
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::EXPORT_HEADER));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(500, function ($chunk) use ($writer, $bar) {
            foreach ($chunk as $r) {
                $options = collect((array) $r->options)->filter()->keys()->implode(', ');

                $writer->addRow(self::textRow([
                    $r->id,
                    $r->raw_name,
                    $r->confidence,
                    implode('; ', (array) $r->issues),
                    $r->dialect,
                    $r->supplier_name,
                    $r->brand_name,
                    $r->model_name,
                    $r->glass_type,
                    $r->year_from,
                    $r->year_to,
                    $r->body_type,
                    $r->doors,
                    $r->eurocode,
                    $r->price !== null ? (float) $r->price : null,
                ]));

                $bar->advance();
            }
        });

        $writer->close();
        $bar->finish();
        $this->newLine(2);

        $this->info("Выгружено {$total} строк: {$path}");
        $this->line('Заполните колонки Марка/Модель/Тип стекла и загрузите обратно:');
        $this->line("  <info>php artisan price:corrections {$path}</info>");
    }
}
