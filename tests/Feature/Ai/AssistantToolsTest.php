<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AssistantTools;
use App\Domain\Identity\BuyerContext;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preserves_offers_exact_money_and_fitment_without_product_n_plus_one(): void
    {
        $product = Product::factory()->create(['is_published' => true, 'eurocode' => 'AI-CATALOG']);
        $offers = Offer::factory()->count(8)->create(['product_id' => $product->id, 'price_retail' => '123.45', 'price_wholesale' => '10.00', 'stock_qty' => 2]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(AssistantTools::class)->call('search_products', ['query' => 'AI-CATALOG'], BuyerContext::guest(str_repeat('a', 64)));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(8, $result['items']);
        $this->assertEqualsCanonicalizing($offers->pluck('id')->all(), array_column($result['items'], 'offer_id'));
        $this->assertSame('123.45', $result['items'][0]['price_kzt']);
        $this->assertSame(['amount_minor' => 12345, 'currency' => 'KZT'], $result['items'][0]['unit_price']);
        $this->assertSame('review_required', $result['items'][0]['fitment']['status']);
        $this->assertStringContainsString($product->slug, $result['items'][0]['url']);
        $productReads = array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select * from "products"'));
        $this->assertCount(1, $productReads);
        $this->assertStringNotContainsString('price_wholesale', json_encode($result));
    }

    public function test_vehicle_options_reach_shared_fitment_rule_and_unknown_camera_requires_question(): void
    {
        $model = CarModel::factory()->create(['needs_review' => false]);
        $product = Product::factory()->create(['is_published' => true, 'glass_type' => 'windshield', 'camera' => false, 'eurocode' => 'AI-FITMENT']);
        Offer::factory()->create(['product_id' => $product->id]);
        Fitment::factory()->create(['product_id' => $product->id, 'car_model_id' => $model->id, 'is_verified' => true, 'year_from' => 2015, 'year_to' => 2025, 'body_type' => null, 'doors' => null, 'note' => null, 'car_generation_id' => null]);
        $buyer = BuyerContext::guest(str_repeat('a', 64));
        $args = ['query' => 'AI-FITMENT', 'car_model_id' => $model->id, 'year' => 2020];
        $missing = app(AssistantTools::class)->call('search_products', $args, $buyer);
        $this->assertSame('review_required', $missing['items'][0]['fitment']['status']);
        $this->assertContains('camera', $missing['items'][0]['missing_options']);

        $options = [];
        foreach (config('ordering.fitment.required_options.windshield') as $option) {
            $options[$option] = (bool) $product->{$option};
        }
        $matched = app(AssistantTools::class)->call('search_products', [...$args, 'options' => $options], $buyer);
        $this->assertSame('matched', $matched['items'][0]['fitment']['status']);
        $mismatch = app(AssistantTools::class)->call('search_products', [...$args, 'options' => [...$options, 'camera' => true]], $buyer);
        $this->assertSame([], $mismatch['items']);
    }

    public function test_rav4_1995_excludes_other_generations_before_limiting_cards(): void
    {
        $model = CarModel::factory()->create(['name' => 'RAV4', 'needs_review' => false]);
        foreach ([2006, 2019, 2000, 2012, 2006, 2000, 2019, 2012, 1994] as $start) {
            $product = Product::factory()->create([
                'is_published' => true, 'glass_type' => 'windshield',
                'name' => "Лобовое стекло Toyota RAV4 {$start}–".($start === 1994 ? '2000' : 'н.в.'),
            ]);
            Offer::factory()->create(['product_id' => $product->id]);
            Fitment::factory()->create([
                'product_id' => $product->id, 'car_model_id' => $model->id,
                'year_from' => $start, 'year_to' => $start === 1994 ? 2000 : null,
            ]);
        }
        $result = app(AssistantTools::class)->searchProducts(['query' => 'Toyota RAV4 1995 лобовое'], BuyerContext::guest(str_repeat('a', 64)));
        $this->assertCount(1, $result['items']);
        $this->assertStringContainsString('1994–2000', $result['items'][0]['name']);
        $this->assertSame(1995, $result['vehicle']['year']);
        $this->assertSame('review_required', $result['items'][0]['fitment']['status']);
    }

    public function test_rav4_single_year_cards_with_empty_fitment_dates_do_not_match_1995(): void
    {
        $model = CarModel::factory()->create(['name' => 'RAV4', 'needs_review' => false]);
        foreach ([
            '1994–2000 (ЗП)', 'изм. 2015 (ДД, полный обогрев)',
            '2019 (ДД, полный обогрев)', 'а 2019 (ДД, камера, полный обогрев)',
        ] as $label) {
            $product = Product::factory()->create(['is_published' => true, 'glass_type' => 'windshield', 'name' => 'Лобовое стекло Toyota RAV4 '.$label]);
            Offer::factory()->create(['product_id' => $product->id]);
            Fitment::factory()->create(['product_id' => $product->id, 'car_model_id' => $model->id, 'year_from' => null, 'year_to' => null]);
        }
        $result = app(AssistantTools::class)->searchProducts(['query' => 'RAV4', 'year' => 1995], BuyerContext::guest(str_repeat('a', 64)));
        $this->assertCount(1, $result['items']);
        $this->assertStringContainsString('1994–2000', $result['items'][0]['name']);
    }

    public function test_vehicle_sentence_does_not_return_other_cars_of_the_same_year(): void
    {
        $t4 = Product::factory()->create([
            'is_published' => true,
            'glass_type' => 'windshield',
            'name' => 'Лобовое стекло Volkswagen TRANSP T4/CARAV 1990–н.в. (ЗП)',
        ]);
        $bmw = Product::factory()->create([
            'is_published' => true,
            'glass_type' => 'windshield',
            'name' => 'Лобовое стекло BMW 3 E46 1998–2005 (ЗП)',
        ]);
        Offer::factory()->create(['product_id' => $t4->id, 'price_retail' => '43500.00', 'stock_qty' => 0]);
        Offer::factory()->create(['product_id' => $bmw->id, 'price_retail' => '23500.00', 'stock_qty' => 0]);

        $result = app(AssistantTools::class)->call(
            'search_products',
            ['query' => 'vw t4 лобовое 1998 года какие варианты'],
            BuyerContext::guest(str_repeat('a', 64)),
        );

        $this->assertNotEmpty($result['items']);
        $this->assertSame([$t4->id], array_values(array_unique(array_column($result['items'], 'product_id'))));
        $this->assertStringNotContainsString('BMW', json_encode($result['items'], JSON_UNESCAPED_UNICODE));
    }

    public function test_invalid_options_are_recoverable_tool_errors_and_old_order_tool_cannot_write(): void
    {
        $tools = app(AssistantTools::class);
        $buyer = BuyerContext::guest(str_repeat('a', 64));
        $invalid = $tools->call('search_products', ['query' => 'Camry', 'options' => ['invented' => true]], $buyer);
        $this->assertSame('VALIDATION_FAILED', $invalid['code']);
        $old = $tools->call('create_order', ['name' => 'Клиент', 'phone' => '77001234567', 'privacy_confirmed' => true], $buyer);
        $this->assertSame('UNKNOWN_TOOL', $old['code']);
        $this->assertNotContains('create_order', array_column(AssistantTools::declarations(), 'name'));
        $this->assertDatabaseCount('orders', 0);
    }
}
