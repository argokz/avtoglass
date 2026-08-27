<?php

namespace App\Catalog;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarModelAlias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Сведение разъехавшегося справочника моделей.
 *
 * Прайсы называют одну машину по-разному, и до сих пор каждое написание
 * заводило отдельную карточку модели: «5», «5 SER», «5 кругл», «5 квадр»,
 * «5 изм» — это всё BMW пятой серии. В меню это выглядит как шесть разных
 * машин, а стёкла к ним разложены по шести кучам.
 *
 * Класс прогоняет уже собранный справочник через нынешние правила разбора
 * и сводит карточки, у которых получилось одно и то же имя. Пересобирать
 * каталог целиком для этого нельзя: сборка заново создаёт товары, а на них
 * висят загруженные фотографии, остатки, резервы и позиции заказов.
 *
 * Осторожность здесь важнее полноты: лишняя карточка в меню — некрасиво,
 * а склеенные Pajero и Pajero Sport — это отгруженное не то стекло.
 * Поэтому сводим только то, что правила признали одной моделью,
 * а спорное оставляем человеку через справочник псевдонимов.
 */
class ModelMerger
{
    private ModelNormalizer $normalizer;

    public function __construct(?ModelNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? CarModelAlias::normalizer();
    }

    /**
     * Что будет сведено. Ничего не меняет — это отчёт для глаз.
     *
     * @return Collection<int,array{brand:string,canonical:string,winner:CarModel,losers:Collection<int,CarModel>}>
     */
    public function plan(): Collection
    {
        $plan = collect();

        foreach (CarBrand::with('models')->get() as $brand) {
            foreach ($this->groupsFor($brand) as $canonical => $models) {
                if ($models->count() < 2) {
                    continue;
                }

                $winner = $this->pickWinner($models, $canonical);
                $losers = $this->keepSafe(
                    $models->reject(fn (CarModel $m) => $m->is($winner))->values(),
                    $canonical
                );

                if ($losers->isEmpty()) {
                    continue;
                }

                $plan->push([
                    'brand' => $brand->name,
                    'canonical' => $canonical,
                    'winner' => $winner,
                    'losers' => $losers,
                ]);
            }
        }

        return $plan;
    }

    /**
     * Что решено не трогать.
     *
     * Отчёт для человека: эти пары правила разбора свести не берутся,
     * и если они действительно одна машина, свести их можно псевдонимом.
     *
     * @return Collection<int,array{brand:string,canonical:string,skipped:Collection<int,CarModel>}>
     */
    public function skipped(): Collection
    {
        $skipped = collect();

        foreach (CarBrand::with('models')->get() as $brand) {
            foreach ($this->groupsFor($brand) as $canonical => $models) {
                if ($models->count() < 2) {
                    continue;
                }

                $winner = $this->pickWinner($models, $canonical);
                $all = $models->reject(fn (CarModel $m) => $m->is($winner))->values();
                $left = $all->diff($this->keepSafe($all, $canonical));

                if ($left->isNotEmpty()) {
                    $skipped->push([
                        'brand' => $brand->name,
                        'canonical' => $canonical,
                        'skipped' => $left->values(),
                    ]);
                }
            }
        }

        return $skipped;
    }

    /**
     * Отсекает слияния, в которых буква могла оказаться частью имени.
     *
     * Одиночная латинская буква в хвосте — обычно индекс поколения: у Opel
     * это Astra F, G, H, J. Но ровно так же выглядят «Tesla Model S»
     * и «Toyota Prius C», где буква и есть машина, и слить их с «Model»
     * или «Prius» — значит показать покупателю чужое стекло.
     *
     * Различаем по количеству: индексы поколений идут выводком, у одной
     * основы их несколько. Одинокая буква подозрительна, и её мы оставляем
     * человеку — лишняя карточка в меню дешевле неверного подбора.
     *
     * @param  Collection<int,CarModel>  $losers
     * @return Collection<int,CarModel>
     */
    private function keepSafe(Collection $losers, string $canonical): Collection
    {
        $letters = $losers
            ->map(fn (CarModel $m) => $this->trailingLetter($m->name, $canonical))
            ->filter()
            ->unique();

        if ($letters->count() >= 2) {
            return $losers;
        }

        return $losers->reject(fn (CarModel $m) => $this->trailingLetter($m->name, $canonical) !== null)->values();
    }

    /**
     * Буква, на которую имя отличается от канонического, или null.
     *
     * Считаем только заглавную: именем модели буква бывает именно такой
     * («Model S», «Prius C», «Kadett E»), а строчная в хвосте — это обрывок
     * примечания кладовщика («Tiggo 8-PRO a»), и придерживать его незачем.
     */
    private function trailingLetter(string $name, string $canonical): ?string
    {
        return preg_match('/^'.preg_quote($canonical, '/').'\s+([A-Z])$/u', trim($name), $m) === 1
            ? $m[1]
            : null;
    }

    /** @return int сколько карточек убрано */
    public function apply(): int
    {
        $removed = 0;

        foreach ($this->plan() as $group) {
            DB::transaction(function () use ($group, &$removed) {
                foreach ($group['losers'] as $loser) {
                    $this->absorb($group['winner'], $loser);
                    $removed++;
                }

                $this->rename($group['winner'], $group['canonical']);
            });
        }

        if ($removed > 0) {
            // Иначе в меню повиснут марки, ведущие на пустую страницу:
            // счётчики считались по старому числу карточек.
            (new CatalogCounters)->refresh();
        }

        return $removed;
    }

    /**
     * Модели марки, сгруппированные по имени после разбора.
     *
     * @return Collection<string,Collection<int,CarModel>>
     */
    private function groupsFor(CarBrand $brand): Collection
    {
        return $brand->models->groupBy(
            fn (CarModel $model) => $this->normalizer->normalize($brand->name, $model->name)->name
        );
    }

    /**
     * Кто остаётся.
     *
     * Сначала тот, кто уже называется правильно, — тогда и адрес страницы
     * не меняется, и ссылки из поиска продолжают работать. Если такого нет,
     * берём карточку с наибольшим числом товаров: у неё больше входящих
     * ссылок и она чаще попадалась покупателям.
     *
     * @param  Collection<int,CarModel>  $models
     */
    private function pickWinner(Collection $models, string $canonical): CarModel
    {
        return $models->sortByDesc(fn (CarModel $m) => [
            $m->name === $canonical ? 1 : 0,
            $m->products_count,
            -$m->id,
        ])->first();
    }

    /**
     * Переносит всё, что ссылалось на лишнюю карточку, и удаляет её.
     *
     * Применимости переносятся с оглядкой на уникальный ключ: у победителя
     * уже может быть точно такая же строка, и тогда переносить нечего —
     * дубль просто исчезает вместе с проигравшим.
     */
    private function absorb(CarModel $winner, CarModel $loser): void
    {
        $existing = DB::table('fitments')
            ->where('car_model_id', $winner->getKey())
            ->get(['product_id', 'year_from', 'year_to', 'body_type'])
            ->map(fn ($row) => $this->fitmentKey($row->product_id, $row->year_from, $row->year_to, $row->body_type))
            ->flip();

        foreach (DB::table('fitments')->where('car_model_id', $loser->getKey())->get() as $fitment) {
            $key = $this->fitmentKey($fitment->product_id, $fitment->year_from, $fitment->year_to, $fitment->body_type);

            if ($existing->has($key)) {
                DB::table('fitments')->where('id', $fitment->id)->delete();

                continue;
            }

            DB::table('fitments')->where('id', $fitment->id)->update(['car_model_id' => $winner->getKey()]);
            $existing->put($key, true);
        }

        // Поколения и машины клиентов просто переезжают: уникальных ключей,
        // которые могли бы столкнуться, у них нет.
        DB::table('car_generations')->where('car_model_id', $loser->getKey())
            ->update(['car_model_id' => $winner->getKey()]);

        DB::table('customers')->where('car_model_id', $loser->getKey())
            ->update(['car_model_id' => $winner->getKey()]);

        $loser->delete();
    }

    private function fitmentKey(int $productId, ?int $from, ?int $to, ?string $body): string
    {
        return $productId.'|'.($from ?? '').'|'.($to ?? '').'|'.($body ?? '');
    }

    /**
     * Приводит имя победителя к каноническому.
     *
     * Адрес страницы меняем только если новый свободен: сломанная ссылка
     * из поисковой выдачи дороже некрасивого адреса.
     */
    private function rename(CarModel $winner, string $canonical): void
    {
        if ($winner->name === $canonical) {
            return;
        }

        $slug = Str::slug(str_replace('-', '', $canonical)) ?: $winner->slug;

        $taken = CarModel::where('car_brand_id', $winner->car_brand_id)
            ->where('slug', $slug)
            ->whereKeyNot($winner->getKey())
            ->exists();

        $winner->update([
            'name' => $canonical,
            'slug' => $taken ? $winner->slug : $slug,
        ]);
    }
}
