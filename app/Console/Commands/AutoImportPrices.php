<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Автоматический импорт прайс-листов поставщиков из папки входящих файлов.
 * Сканирует storage/app/prices/incoming, запускает price:import и перемещает обработанные файлы.
 */
class AutoImportPrices extends Command
{
    protected $signature = 'price:auto-import';

    protected $description = 'Автоматически найти и обработать все новые прайс-листы поставщиков в папке storage/app/prices/incoming';

    public function handle(): int
    {
        $incomingDir = storage_path('app/prices/incoming');
        $processedDir = storage_path('app/prices/processed');

        File::ensureDirectoryExists($incomingDir);
        File::ensureDirectoryExists($processedDir);

        $files = File::files($incomingDir);

        if (empty($files)) {
            $this->info("Нет новых файлов для импорта в {$incomingDir}");

            return self::SUCCESS;
        }

        $this->info('Найдено файлов для импорта: '.count($files));

        foreach ($files as $file) {
            $ext = mb_strtolower($file->getExtension());
            if (! in_array($ext, ['xlsx', 'xls', 'csv'])) {
                continue;
            }

            $filename = $file->getFilename();
            $this->info("Обработка прайс-листа: {$filename}...");

            $exitCode = Artisan::call('price:import', [
                'file' => $file->getRealPath(),
                '--source' => "Авто-импорт {$filename} ".now()->format('d.m.Y H:i'),
            ]);

            if ($exitCode === self::SUCCESS) {
                $destPath = $processedDir.'/'.date('Ymd_His').'_'.$filename;
                File::move($file->getRealPath(), $destPath);
                $this->info("✓ Файл {$filename} успешно импортирован и перенесён в processed.");
            } else {
                $this->error("✗ Ошибка при импорте файла {$filename}.");
            }
        }

        return self::SUCCESS;
    }
}
