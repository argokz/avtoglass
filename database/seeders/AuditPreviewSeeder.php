<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Additional synthetic variants for the price-selection browser regression. */
class AuditPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('preview') || DB::selectOne('SELECT current_database() AS name')->name !== 'avtoglasskz_modernization_preview') {
            throw new RuntimeException('Audit fixtures require the isolated preview database.');
        }
        $product = Product::where('slug', 'preview-camry-70')->firstOrFail();
        $product->forceFill(['origin_country' => 'KZ'])->save();
        foreach (['audit-priced' => ['Демо · расширенная гарантия', '41750.55'], 'audit-unknown' => ['Демо · под заказ', null]] as $slug => [$name, $price]) {
            $supplier = Supplier::where('slug', $slug)->first()
                ?? Supplier::factory()->create(['slug' => $slug, 'name' => $name, 'is_active' => true]);
            if (! Offer::where('product_id', $product->id)->where('supplier_id', $supplier->id)->exists()) {
                Offer::factory()->create([
                    'product_id' => $product->id, 'supplier_id' => $supplier->id,
                    'article' => strtoupper($slug), 'price_retail' => $price,
                    'price_wholesale' => null, 'stock_qty' => 0, 'is_active' => true,
                ]);
            }
        }
        $product->refreshPricing();
    }
}
