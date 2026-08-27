<?php

namespace App\Console\Commands;

use App\Catalog\ModelMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сводит карточки моделей, которые оказались одной машиной.
 *
 * По умолчанию только показывает план: слияние необратимо, а ошибка в нём —
 * это отгруженное не то стекло. Сначала смотрят глазами, потом применяют.
 */
class MergeCarModels extends Command
{
    protected $signature = 'catalog:merge-models
        {--apply : Применить, а не только показать}
        {--limit=25 : Сколько групп показать в отчёте}';

    protected $description = 'Свести дубли моделей в справочнике по нынешним правилам разбора';

    public function handle(ModelMerger $merger): int
    {
        $plan = $merger->plan();

        if ($plan->isEmpty()) {
            $this->info('Справочник чист: сводить нечего');

            return self::SUCCESS;
        }

        $this->report($plan);
        $this->reportSkipped($merger);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Это только план. Чтобы применить: php artisan catalog:merge-models --apply');

            return self::SUCCESS;
        }

        $before = DB::table('car_models')->count();
        $removed = $merger->apply();

        $this->newLine();
        $this->info("Убрано карточек: {$removed}");
        $this->line('Было моделей: '.$before.', стало: '.DB::table('car_models')->count());

        return self::SUCCESS;
    }

    private function report($plan): void
    {
        $groups = $plan->count();
        $models = $plan->sum(fn (array $g) => $g['losers']->count());

        $this->info("Групп к слиянию: {$groups}, лишних карточек: {$models}");
        $this->newLine();

        foreach ($plan->sortByDesc(fn (array $g) => $g['losers']->count())->take((int) $this->option('limit')) as $group) {
            $this->line(sprintf(
                '<fg=cyan>%s %s</> <fg=gray>← %s</>',
                $group['brand'],
                $group['canonical'],
                $group['losers']->pluck('name')->implode(' | ')
            ));
        }

        if ($groups > (int) $this->option('limit')) {
            $this->newLine();
            $this->line('... и ещё групп: '.($groups - (int) $this->option('limit')));
        }
    }

    /**
     * Что правила трогать не взялись.
     *
     * Это не ошибка, а граница автоматики: если эти пары действительно одна
     * машина, их сводят вручную через справочник псевдонимов.
     */
    private function reportSkipped(ModelMerger $merger): void
    {
        $skipped = $merger->skipped();

        if ($skipped->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Оставлено человеку (буква могла оказаться частью имени):');

        foreach ($skipped->take(15) as $group) {
            $this->line(sprintf(
                '  <fg=gray>%s</> %s <fg=gray>и</> %s',
                $group['brand'],
                $group['canonical'],
                $group['skipped']->pluck('name')->implode(', ')
            ));
        }

        if ($skipped->count() > 15) {
            $this->line('  ... и ещё пар: '.($skipped->count() - 15));
        }
    }
}
