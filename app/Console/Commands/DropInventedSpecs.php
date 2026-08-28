<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Убирает характеристики, которых никто не измерял.
 *
 * Генератор изображений какое-то время дозаполнял карточки «правдоподобными»
 * значениями: размеры через rand() по типу стекла, тонировку «зелёная»
 * по умолчанию, описание шаблонной фразой. На витрине всё это выглядит
 * как характеристика товара.
 *
 * Для покупателя характеристики — последняя проверка перед заказом: он меряет
 * снятое стекло, смотрит на его цвет и сверяет со страницей. Пустое поле
 * заставит позвонить менеджеру. Выдуманное выглядит достоверно и приводит
 * к заказу не того стекла.
 *
 * Основание оставляем только там, где оно есть:
 *   габариты   — разбор нашёл их в самой строке прайса (стейджинг);
 *   тонировка  — её кодируют буквы еврокода;
 *   отверстия  — то же, что габариты;
 *   описание   — написано человеком, а не собрано из названия.
 */
class DropInventedSpecs extends Command
{
    protected $signature = 'catalog:drop-invented {--apply : Стереть, а не только показать}';

    protected $description = 'Убрать характеристики, не подтверждённые прайсом или еврокодом';

    /** Шаблон, которым генератор заполнял описание всем подряд. */
    private const FILLER = '%с заводской шелкографией и креплениями%';

    public function handle(): int
    {
        $apply = $this->option('apply');

        $counts = [
            'габариты' => $this->dimensions($apply),
            'тонировка' => $this->tint($apply),
            'отверстия' => $this->holes($apply),
            'описание' => $this->description($apply),
        ];

        $this->table(
            ['Характеристика', $apply ? 'Стёрто' : 'Не подтверждено'],
            collect($counts)->map(fn ($n, $label) => [$label, $n])->values()->all(),
        );

        if (! $apply && array_sum($counts) > 0) {
            $this->newLine();
            $this->warn('Это только подсчёт. Чтобы стереть: --apply');
        }

        return self::SUCCESS;
    }

    /**
     * Настоящие габариты видно по стейджингу: разбор кладёт найденное
     * в названии в price_import_rows.width_mm и больше ниоткуда.
     */
    private function dimensions(bool $apply): int
    {
        $confirmed = DB::table('price_import_rows')
            ->whereNotNull('width_mm')
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        $query = Product::whereNotNull('width_mm')->whereNotIn('id', $confirmed);
        $count = $query->count();

        if ($apply && $count > 0) {
            $query->update(['width_mm' => null, 'height_mm' => null]);
        }

        return $count;
    }

    /**
     * Цвет кодируют буквы еврокода. Карточкам без еврокода или без цветовой
     * пары в нём утверждать цвет не с чего.
     */
    private function tint(bool $apply): int
    {
        // Оставляем только те, у кого в еврокоде есть цветовая пара:
        // «нет кода» и «в коде нет цвета» — оба случая без основания.
        $confirmed = Product::whereNotNull('eurocode')->where(function ($q) {
            foreach (['GS', 'GY', 'BS', 'BL', 'BZ', 'CL'] as $marker) {
                $q->orWhere('eurocode', 'ilike', '%'.$marker.'%');
            }
        })->pluck('id');

        $query = Product::whereNotNull('tint')->whereNotIn('id', $confirmed);

        $count = $query->count();

        if ($apply && $count > 0) {
            $query->update(['tint' => null]);
        }

        return $count;
    }

    /**
     * Ноль отверстий тоже утверждение. Оно нигде не показывается, но и держать
     * его как факт незачем: настоящее число приходит из прайса вместе с размерами.
     */
    private function holes(bool $apply): int
    {
        $confirmed = DB::table('price_import_rows')
            ->whereNotNull('holes_count')
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

        $query = Product::where('holes_count', 0)->whereNotIn('id', $confirmed);
        $count = $query->count();

        if ($apply && $count > 0) {
            $query->update(['holes_count' => null]);
        }

        return $count;
    }

    /**
     * Шаблонное описание повторяло заголовок и утверждало то, чего мы не знаем.
     * Заодно это пять тысяч почти одинаковых страниц — для поиска пустой текст.
     */
    private function description(bool $apply): int
    {
        $query = Product::where('description', 'like', self::FILLER);
        $count = $query->count();

        if ($apply && $count > 0) {
            $query->update(['description' => null]);
        }

        return $count;
    }
}
