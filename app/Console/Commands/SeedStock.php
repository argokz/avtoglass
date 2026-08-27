<?php

namespace App\Console\Commands;

use App\Enums\GlassType;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Заглушка вместо выгрузки остатков из 1С.
 *
 * Пока интеграции нет, склад заполняется правдоподобно, а не равномерно:
 * стекло — товар объёмный и дорогой, его не держат десятками. Реальный склад
 * выглядит так, что большая часть позиций идёт под заказ, в наличии лежит
 * ходовое, а расходники есть почти всегда.
 *
 * Когда появится 1С, команда заменяется на импорт, а поле stock_qty
 * и всё, что от него зависит, остаётся прежним.
 */
class SeedStock extends Command
{
    protected $signature = 'catalog:stock
        {--reset : Обнулить остатки вместо заполнения}
        {--share=25 : Доля позиций в наличии, %}';

    protected $description = 'Заполнить остатки случайными значениями (пока нет выгрузки из 1С)';

    public function handle(): int
    {
        if ($this->option('reset')) {
            Offer::query()->update(['stock_qty' => 0]);
            $this->refreshProducts();
            $this->info('Остатки обнулены.');

            return self::SUCCESS;
        }

        $share = max(0, min(100, (int) $this->option('share')));

        $this->info("Заполняю остатки: примерно {$share}% позиций в наличии");

        $bar = $this->output->createProgressBar(Offer::count());
        $bar->start();

        Offer::with('product')->chunkById(500, function ($offers) use ($share, $bar) {
            DB::transaction(function () use ($offers, $share, $bar) {
                foreach ($offers as $offer) {
                    $offer->update(['stock_qty' => $this->quantityFor($offer, $share)]);
                    $bar->advance();
                }
            });
        });

        $bar->finish();
        $this->newLine(2);

        $this->refreshProducts();
        $this->report();

        return self::SUCCESS;
    }

    /**
     * Расходники держат на складе почти всегда и помногу, стёкла — редко
     * и поштучно. Дешёвое ходовое стекло лежит чаще дорогого редкого.
     */
    private function quantityFor(Offer $offer, int $share): int
    {
        $type = $offer->product?->glass_type;

        if ($type === GlassType::Consumable) {
            return random_int(0, 100) < 80 ? random_int(5, 60) : 0;
        }

        if ($type === GlassType::Service) {
            return 0;   // услуга не лежит на складе
        }

        $chance = $share;

        // Дешёвое расходится и завозится чаще — держат охотнее
        $price = (float) ($offer->price_retail ?? 0);
        if ($price > 0 && $price < 20000) {
            $chance += 15;
        } elseif ($price > 80000) {
            $chance -= 10;
        }

        if (random_int(1, 100) > max(1, $chance)) {
            return 0;
        }

        // Стекло объёмное: больше пяти штук одной позиции почти не держат
        return random_int(1, 100) <= 70 ? random_int(1, 2) : random_int(3, 5);
    }

    /** in_stock у карточки — производное от остатков её предложений. */
    private function refreshProducts(): void
    {
        // Булево присваиваем булевым: PostgreSQL не приводит 1/0 к boolean
        DB::statement('
            UPDATE products SET in_stock = (
                SELECT COALESCE(SUM(stock_qty), 0) > 0
                FROM offers WHERE offers.product_id = products.id AND offers.is_active = true
            )
        ');
    }

    private function report(): void
    {
        $inStock = Product::where('is_published', true)->where('in_stock', true)->count();
        $total = Product::where('is_published', true)->count();

        $this->table(
            ['Показатель', 'Значение'],
            [
                ['Карточек в наличии', $inStock.' из '.$total],
                ['Доля', $total > 0 ? round($inStock / $total * 100).' %' : '—'],
                ['Позиций с остатком', Offer::where('stock_qty', '>', 0)->count()],
                ['Всего единиц на складе', (int) Offer::sum('stock_qty')],
            ]
        );
    }
}
