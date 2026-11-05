<?php

namespace App\Catalog;

use App\Console\Commands\BuildCatalog;
use App\Enums\GlassType;
use App\Models\Offer;
use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Models\Product;
use App\Models\Supplier;

/**
 * «Что изменится, если собрать каталог» — до того, как оно изменится.
 *
 * Прайс приходит Excel-файлом от людей, и ошибка в нём (лишний ноль, цена
 * в рублях) уходит на витрину как окончательная цена. Поэтому сборке
 * предшествует сводка: сколько карточек появится, у скольких цена вырастет
 * или упадёт и какие скачки подозрительны. Читает только — ничего не пишет.
 */
final class PriceImportPreview
{
    /** Изменение цены больше чем вдвое или вдвое меньше — повод посмотреть глазами. */
    public const SUSPICIOUS_RATIO = 2.0;

    public const SUSPICIOUS_LIMIT = 15;

    private const CHUNK = 1000;

    /**
     * @return array{rows:int,skipped:int,review:int,products_new:int,offers_new:int,price_up:int,price_down:int,
     *               price_same:int,deactivated:int,suspicious:list<array{name:string,supplier:string,old:float,new:float}>}
     */
    public function for(PriceImportRun $run): array
    {
        $supplierIds = Supplier::query()->pluck('id', 'slug')->all();
        $supplierNames = Supplier::query()->pluck('name', 'id')->all();
        $eligible = $this->eligible($run);

        $stats = ['rows' => (clone $eligible)->count(), 'skipped' => 0, 'review' => 0, 'products_new' => 0, 'offers_new' => 0,
            'price_up' => 0, 'price_down' => 0, 'price_same' => 0, 'deactivated' => 0, 'suspicious' => []];
        $stats['skipped'] = PriceImportRow::query()->where('price_import_run_id', $run->id)->count() - $stats['rows'];
        // После сборки статус строк меняется на applied — число «на проверке» берём из итогов разбора.
        $stats['review'] = (int) $run->rows_review;

        $newSignatures = [];
        /** @var array<string,array{0:?Offer,1:PriceImportRow,2:string}> ключ предложения => последняя строка */
        $latest = [];

        $eligible->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$newSignatures, &$latest, $supplierIds, $supplierNames) {
            $signatures = $rows->mapWithKeys(fn (PriceImportRow $row) => [$row->id => PriceRowSignature::make($row)]);
            $products = Product::query()->whereIn('signature', $signatures->unique()->values())->pluck('id', 'signature');
            $offers = Offer::query()->whereIn('product_id', $products->values())
                ->get(['product_id', 'supplier_id', 'article', 'price_wholesale', 'is_active'])
                ->keyBy(fn (Offer $offer) => $offer->product_id.'|'.$offer->supplier_id.'|'.$offer->article);

            foreach ($rows as $row) {
                $productId = $products[$signatures[$row->id]] ?? null;
                if ($productId === null) {
                    $newSignatures[$signatures[$row->id]] = true;
                }
                $supplierId = $supplierIds[BuildCatalog::supplierSlug((string) $row->dialect)] ?? null;
                $key = ($productId ?? $signatures[$row->id]).'|'.($supplierId ?? $row->dialect).'|'.$row->raw_article;
                // Дубль в прайсе: сборка идёт по id, и побеждает последняя строка — здесь так же.
                $latest[$key] = [$productId && $supplierId ? $offers->get($key) : null, $row, $supplierNames[$supplierId] ?? ''];
            }
        });

        foreach ($latest as [$offer, $row, $supplier]) {
            $offer === null ? $stats['offers_new']++ : $this->comparePrice($stats, $offer, $row, $supplier);
        }

        $stats['products_new'] = count($newSignatures);
        usort($stats['suspicious'], fn ($a, $b) => max($b['new'], $b['old']) / max(1, min($b['new'], $b['old']))
            <=> max($a['new'], $a['old']) / max(1, min($a['new'], $a['old'])));
        $stats['suspicious'] = array_slice($stats['suspicious'], 0, self::SUSPICIOUS_LIMIT);

        return $stats;
    }

    /** Те же строки, что возьмёт catalog:build: стекло с маркой или расходник. */
    private function eligible(PriceImportRun $run)
    {
        return PriceImportRow::query()->where('price_import_run_id', $run->id)
            ->whereNotNull('glass_type')
            ->where(fn ($q) => $q->whereNotNull('brand_name')->orWhere('glass_type', GlassType::Consumable->value));
    }

    private function comparePrice(array &$stats, Offer $offer, PriceImportRow $row, string $supplier): void
    {
        if ($row->price === null) {
            $offer->is_active ? $stats['deactivated']++ : $stats['price_same']++;

            return;
        }
        $old = (float) $offer->price_wholesale;
        $new = (float) $row->price;
        if (abs($new - $old) < 0.005) {
            $stats['price_same']++;

            return;
        }
        $new > $old ? $stats['price_up']++ : $stats['price_down']++;
        if ($old > 0 && $new > 0 && max($new, $old) / min($new, $old) >= self::SUSPICIOUS_RATIO) {
            $stats['suspicious'][] = ['name' => (string) $row->raw_name, 'supplier' => $supplier, 'old' => $old, 'new' => $new];
        }
    }
}
