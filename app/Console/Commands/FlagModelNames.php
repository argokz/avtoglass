<?php

namespace App\Console\Commands;

use App\Catalog\ModelNameAudit;
use App\Models\CarModel;
use Illuminate\Console\Command;

class FlagModelNames extends Command
{
    protected $signature = 'catalog:flag-models {--apply : записать пометки, иначе только показать}';

    protected $description = 'Помечает названия моделей, в которых остался хвост от разбора прайса';

    public function handle(ModelNameAudit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $found = [];

        CarModel::query()->with('brand')->orderBy('name')->chunk(200, function ($models) use ($audit, &$found) {
            foreach ($models as $model) {
                if ($reason = $audit->reason($model->name, $model->brand?->name)) {
                    $found[] = [$model, $reason];
                }
            }
        });

        if ($found === [] && ! $apply) {
            $this->info('Подозрительных названий нет.');

            return self::SUCCESS;
        }

        if ($found !== []) {
            $this->печатьТаблицы($found);
        }

        if (! $apply) {
            $this->warn('Показано '.count($found).'. Запустите с --apply, чтобы поставить пометки.');

            return self::SUCCESS;
        }

        foreach ($found as [$model, $reason]) {
            $model->forceFill(['needs_review' => true, 'review_note' => $reason])->save();
        }

        /*
         * Снимаем пометку с тех, кого уже поправили: иначе очередь проверки
         * никогда не пустеет и менеджер перестаёт в неё заходить.
         */
        $ids = array_map(fn ($row) => $row[0]->id, $found);
        $снято = CarModel::query()
            ->where('needs_review', true)
            ->whereNotIn('id', $ids)
            ->update(['needs_review' => false, 'review_note' => null]);

        $this->info('Помечено: '.count($found).'. Снято пометок с исправленных: '.$снято.'.');

        return self::SUCCESS;
    }

    /** @param  array<int,array{0:CarModel,1:string}>  $found */
    private function печатьТаблицы(array $found): void
    {
        $this->table(
            ['Марка', 'Модель', 'Почему', 'Подборов'],
            array_map(fn ($row) => [
                $row[0]->brand?->name ?? '—',
                $row[0]->name,
                $row[1],
                $row[0]->fitments()->count(),
            ], $found)
        );
    }
}
