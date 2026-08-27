<?php

namespace App\Console\Commands;

use App\Catalog\MediaImport;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Раскладывает по карточкам снимки и характеристики из внешнего каталога.
 *
 * Принимает два вида источника, потому что приходят они по-разному:
 *
 *   папка   — снимки, названные кодом: 4502AGS.jpg, 2445AGNMV6T.png.
 *             Так отдают медиа-пакеты заводы и так проще всего сложить
 *             собственные фотографии.
 *
 *   таблица — CSV с колонкой кода и любыми из: image, width_mm, height_mm,
 *             holes_count, tint, oem_code, description. Так выглядит выгрузка
 *             из кабинета поставщика.
 *
 * По умолчанию только показывает, что сойдётся: чужой файл может оказаться
 * не от того каталога, и заметить это надо до того, как он ляжет в карточки.
 */
class ImportProductMedia extends Command
{
    protected $signature = 'catalog:import-media
        {path : Папка со снимками или CSV-файл}
        {--apply : Записать, а не только показать}';

    protected $description = 'Загрузить снимки и характеристики из каталога поставщика или производителя';

    public function handle(MediaImport $import): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("Не найдено: {$path}");

            return self::FAILURE;
        }

        $entries = is_dir($path) ? $this->fromDirectory($path) : $this->fromCsv($path);

        if ($entries->isEmpty()) {
            $this->warn('В источнике нечего разбирать');

            return self::SUCCESS;
        }

        $this->info('Записей в источнике: '.$entries->count());

        $result = $import->apply($entries, dryRun: ! $this->option('apply'));

        $this->line('  сошлось с карточками: '.$result['matched']);

        if ($this->option('apply')) {
            $this->line('  добавлено снимков: '.$result['images']);
            $this->line('  заполнено полей: '.$result['fields']);
        }

        $this->reportUnmatched($result['unmatched']);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Это только проверка. Чтобы записать: --apply');
        }

        return self::SUCCESS;
    }

    /** Снимки, названные кодом: имя файла и есть ключ. */
    private function fromDirectory(string $path): Collection
    {
        $files = glob(rtrim($path, '/\\').'/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];

        return collect($files)->map(fn (string $file) => [
            'code' => pathinfo($file, PATHINFO_FILENAME),
            'image' => $file,
        ]);
    }

    /**
     * CSV с заголовком. Колонка кода называется code, eurocode или article —
     * у каждого поставщика по-своему, и переименовывать файл руками незачем.
     */
    private function fromCsv(string $path): Collection
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            fclose($handle);

            return collect();
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $header);
        $codeColumn = $this->findCodeColumn($header);

        if ($codeColumn === null) {
            fclose($handle);
            $this->error('В таблице нет колонки с кодом: ожидается code, eurocode или article');

            return collect();
        }

        $rows = collect();
        $base = dirname($path);

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $entry = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
            $entry['code'] = $entry[$codeColumn] ?? '';

            // Путь к снимку в таблице пишут относительно самого файла
            if (filled($entry['image'] ?? null) && ! is_file($entry['image'])) {
                $entry['image'] = $base.DIRECTORY_SEPARATOR.ltrim($entry['image'], '/\\');
            }

            $rows->push($entry);
        }

        fclose($handle);

        return $rows;
    }

    private function findCodeColumn(array $header): ?string
    {
        foreach (['code', 'eurocode', 'article', 'артикул', 'еврокод'] as $candidate) {
            if (in_array($candidate, $header, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Не сошедшееся показываем: это либо каталог не от того поставщика,
     * либо у нас нет этих позиций — и то и другое надо увидеть глазами.
     */
    private function reportUnmatched(array $unmatched): void
    {
        if ($unmatched === []) {
            return;
        }

        $this->newLine();
        $this->warn('Не нашли карточку по коду: '.count($unmatched));
        $this->line('  '.implode(', ', array_slice($unmatched, 0, 12)).(count($unmatched) > 12 ? ' …' : ''));
    }
}
