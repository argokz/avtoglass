<?php

namespace App\Console\Commands;

use App\Catalog\FitmentYears;
use Illuminate\Console\Command;

/**
 * Уточняет годы применимости по поколению.
 *
 * Запускается после сборки каталога и отдельно — руками, когда в справочник
 * поколений добавили годы: новые годы должны разойтись по уже разобранным
 * позициям, а не ждать следующей переливки прайсов.
 */
class FillFitmentYears extends Command
{
    protected $signature = 'catalog:fill-years';

    protected $description = 'Проставить годы применимости там, где известно поколение';

    public function handle(FitmentYears $years): int
    {
        $filled = $years->backfill();

        $this->info($filled > 0
            ? "Уточнено применимостей: {$filled}"
            : 'Уточнять нечего');

        return self::SUCCESS;
    }
}
