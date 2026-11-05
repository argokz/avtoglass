<?php

namespace Tests\Feature\Catalog;

use App\Catalog\ProductFilter;
use App\Catalog\ProductQuery;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\SessionBuyer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Приоритет отечественного товара: буст в выдаче и фильтр «Сделано в Казахстане».
 */
class MadeInKzTest extends TestCase
{
    use RefreshDatabase;

    public function test_казахстанский_товар_идёт_раньше_более_дешёвого_импортного(): void
    {
        $import = Product::factory()->create(['name' => 'Лобовое Camry импорт', 'price_min' => 20000, 'price_max' => 20000]);
        $kz = Product::factory()->create(['name' => 'Лобовое Camry KZ', 'price_min' => 30000, 'price_max' => 30000, 'origin_country' => 'KZ']);

        $page = app(ProductQuery::class)->paginate(new ProductFilter(query: 'Camry'));

        $this->assertSame($kz->id, $page->items()[0]->id);
    }

    public function test_фильтр_kz_оставляет_только_отечественное(): void
    {
        Product::factory()->create(['name' => 'Лобовое Camry импорт']);
        $kz = Product::factory()->create(['name' => 'Лобовое Camry KZ', 'origin_country' => 'KZ']);

        $page = app(ProductQuery::class)->paginate(new ProductFilter(query: 'Camry', kzOnly: true));

        $this->assertSame(1, $page->total());
        $this->assertSame($kz->id, $page->items()[0]->id);
    }

    public function test_буст_kz_в_общем_поиске_офферов(): void
    {
        $importSupplier = Supplier::factory()->create(['country' => 'Китай']);
        $kzSupplier = Supplier::factory()->create(['country' => 'Казахстан']);

        $import = Product::factory()->create(['name' => 'Лобовое Camry импорт', 'origin_country' => 'CN']);
        $kz = Product::factory()->create(['name' => 'Лобовое Camry KZ', 'origin_country' => 'KZ']);

        Offer::factory()->create(['product_id' => $import->id, 'supplier_id' => $importSupplier->id, 'price_retail' => 20000]);
        Offer::factory()->create(['product_id' => $kz->id, 'supplier_id' => $kzSupplier->id, 'price_retail' => 25000]);

        $result = app(SearchOffers::class)->search(SessionBuyer::current(), ['q' => 'Camry']);

        $this->assertNotEmpty($result['items']);
        $this->assertSame($kz->id, $result['items'][0]['product_id']);
        $this->assertTrue($result['items'][0]['made_in_kz']);
    }
}
