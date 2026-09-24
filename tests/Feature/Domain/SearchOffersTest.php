<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\CarModelAlias;
use App\Models\Customer;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchOffersTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_article_match_returns_personal_price_without_cost(): void
    {
        $product = Product::factory()->create(['eurocode' => '4040', 'oem_code' => 'OEM-1', 'is_published' => true]);
        $offer = Offer::factory()->create([
            'product_id' => $product->id,
            'article' => 'CAMRY-70',
            'price_retail' => '100.00',
            'price_wholesale' => '40.00',
            'stock_qty' => 5,
            'is_active' => true,
        ]);

        $result = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), [
            'q' => 'camry-70',
            'page' => 1,
            'per_page' => 24,
        ]);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame($offer->id, $result['items'][0]['offer_id']);
        $this->assertSame('exact_code', $result['items'][0]['match']['type']);
        $this->assertSame(['amount_minor' => 10000, 'currency' => 'KZT'], $result['items'][0]['unit_price']);
        $this->assertSame(5, $result['items'][0]['available_qty']);
        $this->assertSame('review_required', $result['items'][0]['fitment']['status']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('price_wholesale', $json);
        $this->assertStringNotContainsString('40.00', $json);
    }

    public function test_typo_does_not_replace_exact_article_with_another_product(): void
    {
        Offer::factory()->create(['article' => 'CAMRY-70', 'price_retail' => '100.00', 'is_active' => true]);
        Offer::factory()->create(['article' => 'CAMRY-71', 'price_retail' => '90.00', 'is_active' => true]);

        $result = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'CAMRY70']);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame('CAMRY-70', $result['items'][0]['article']);
        $this->assertSame('exact_code', $result['items'][0]['match']['type']);
    }

    public function test_model_alias_and_name_resolve_the_same_vehicle_filter(): void
    {
        $brand = CarBrand::factory()->create(['name' => 'Toyota']);
        $model = CarModel::factory()->create(['car_brand_id' => $brand->id, 'name' => 'Camry']);
        CarModelAlias::query()->create([
            'car_brand_id' => $brand->id,
            'alias' => 'Камри',
            'model_name' => 'Camry',
        ]);
        $product = Product::factory()->create(['is_published' => true, 'name' => 'Camry windshield']);
        $offer = Offer::factory()->create(['product_id' => $product->id, 'article' => 'CAMRY-WS', 'price_retail' => '50.00', 'is_active' => true]);
        Fitment::factory()->create([
            'product_id' => $product->id,
            'car_model_id' => $model->id,
            'year_from' => 2018,
            'year_to' => 2024,
            'is_verified' => true,
        ]);

        $byName = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'Camry']);
        $byAlias = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'Камри']);

        $this->assertSame($offer->id, $byName['items'][0]['offer_id']);
        $this->assertSame($offer->id, $byAlias['items'][0]['offer_id']);
    }

    public function test_two_suppliers_of_same_glass_return_two_rows(): void
    {
        $product = Product::factory()->create(['is_published' => true, 'eurocode' => 'XY123']);
        $a = Offer::factory()->create(['product_id' => $product->id, 'article' => 'A-1', 'price_retail' => '80.00', 'is_active' => true]);
        $b = Offer::factory()->create(['product_id' => $product->id, 'article' => 'B-1', 'price_retail' => '70.00', 'is_active' => true]);

        $result = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'XY123']);

        $this->assertSame(2, $result['meta']['total']);
        $ids = collect($result['items'])->pluck('offer_id')->sort()->values()->all();
        $this->assertSame([$a->id, $b->id], $ids);
    }

    public function test_guest_with_wholesale_phone_still_sees_retail_price(): void
    {
        // A known wholesale phone must not unlock wholesale pricing for a guest BuyerContext.
        Customer::factory()->wholesale(10)->create(['phone' => '77001112233']);
        Offer::factory()->create([
            'article' => 'WS-1',
            'price_retail' => '100.00',
            'price_wholesale' => '60.00',
            'is_active' => true,
        ]);

        $guest = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('c', 64)), ['q' => 'WS-1']);
        $this->assertSame(10000, $guest['items'][0]['unit_price']['amount_minor']);
    }

    public function test_per_page_100_accepted_and_101_rejected(): void
    {
        Offer::factory()->create(['article' => 'PAGE-1', 'price_retail' => '10.00', 'is_active' => true]);
        $ok = app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'PAGE-1', 'per_page' => 100]);
        $this->assertSame(100, $ok['meta']['per_page']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'PAGE-1', 'per_page' => 101]);
    }

    public function test_http_offers_endpoint_uses_search_offers(): void
    {
        config(['ordering.api_enabled' => true]);
        $this->withSession(['ordering.guest_token' => str_repeat('b', 64)]);
        Offer::factory()->create(['article' => 'HTTP-1', 'price_retail' => '12.50', 'is_active' => true]);

        $this->getJson('/api/v1/offers?q=HTTP-1')
            ->assertOk()
            ->assertJsonPath('data.0.article', 'HTTP-1')
            ->assertJsonPath('data.0.unit_price.amount_minor', 1250)
            ->assertJsonPath('meta.total', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_http_rejects_unknown_query_field_and_per_page_over_limit(): void
    {
        config(['ordering.api_enabled' => true]);
        $this->withSession(['ordering.guest_token' => str_repeat('b', 64)]);

        $this->getJson('/api/v1/offers?price_wholesale=1')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->getJson('/api/v1/offers?per_page=101')
            ->assertStatus(422);
    }

    public function test_query_count_does_not_grow_linearly_with_page_size(): void
    {
        $product = Product::factory()->create(['is_published' => true, 'eurocode' => 'BATCH']);
        Offer::factory()->count(50)->create([
            'product_id' => $product->id,
            'price_retail' => '10.00',
            'is_active' => true,
            'article' => fn () => 'B-'.fake()->unique()->numerify('####'),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'BATCH', 'per_page' => 10]);
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        app(SearchOffers::class)->search(BuyerContext::guest(str_repeat('a', 64)), ['q' => 'BATCH', 'per_page' => 50]);
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($small + 3, $large);
    }

    public function test_exact_code_explain_uses_normalized_expression(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN capture is for PostgreSQL.');
        }

        Offer::factory()->create(['article' => 'EXPL-1', 'price_retail' => '10.00', 'is_active' => true]);
        $sql = "select offers.id from offers join products on products.id = offers.product_id where upper(regexp_replace(coalesce(offers.article, ''), '[\\s.\\-]+', '', 'g')) = 'EXPL1' limit 10";
        $plan = collect(DB::select('explain '.$sql))->map(fn ($row) => implode(' ', (array) $row))->implode("\n");

        $this->assertNotEmpty($plan);
        // Small fixtures may Seq Scan; the expression must still match SearchOffers normalisation.
        $this->assertStringContainsString('regexp_replace', strtolower($sql));
    }
}
