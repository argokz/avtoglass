<?php

namespace App\Catalog;

use App\Models\PriceImportRow;
use App\Models\Product;
use App\Services\PriceList\NameParser;

/**
 * Достаёт габариты для уже собранных карточек.
 *
 * Разбор находил их всегда, но при записи строки в стейджинг поля не
 * перечислили, и находка терялась. Пересобирать каталог ради этого нельзя:
 * сборка заново создаёт товары, а на них висят фотографии, остатки, резервы
 * и позиции заказов. Поэтому названия перечитываем на месте — исходный текст
 * строки прайса никуда не делся.
 */
class DimensionsBackfill
{
    public function __construct(private readonly NameParser $parser = new NameParser) {}

    /** @return array{rows:int,products:int} сколько строк уточнено и сколько карточек заполнено */
    public function run(): array
    {
        $rows = 0;
        $products = 0;

        PriceImportRow::query()
            ->whereNull('width_mm')
            ->whereNotNull('raw_name')
            ->chunkById(500, function ($chunk) use (&$rows, &$products) {
                foreach ($chunk as $row) {
                    $parsed = $this->parser->parse($row->raw_name);

                    if ($parsed->widthMm === null && $parsed->holesCount === null) {
                        continue;
                    }

                    $row->update([
                        'width_mm' => $parsed->widthMm,
                        'height_mm' => $parsed->heightMm,
                        'holes_count' => $parsed->holesCount,
                    ]);

                    $rows++;

                    $products += $this->fillProduct($row->product_id, $parsed->widthMm, $parsed->heightMm, $parsed->holesCount);
                }
            });

        return ['rows' => $rows, 'products' => $products];
    }

    /**
     * Заполняем только пустое: у карточки может быть несколько строк прайса
     * от разных поставщиков, и размеры у них расходятся на пару миллиметров.
     * Первое найденное значение не хуже любого другого, а переписывать его
     * каждой следующей строкой — значит показывать покупателю то одно, то другое.
     */
    private function fillProduct(?int $productId, ?int $width, ?int $height, ?int $holes): int
    {
        if ($productId === null || $width === null) {
            return 0;
        }

        $filled = Product::whereKey($productId)
            ->whereNull('width_mm')
            ->update([
                'width_mm' => $width,
                'height_mm' => $height,
                'holes_count' => $holes,
            ]);

        return $filled;
    }
}
