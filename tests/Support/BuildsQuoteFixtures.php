<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\SalesMode;
use App\Domain\Pricing\QuoteDraft;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\ServicePoint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsQuoteFixtures
{
    protected function setUp(): void
    {
        parent::setUp();
        $at = CarbonImmutable::parse('2026-09-20T05:00:00+00:00');
        $this->travelTo($at);
        CarbonImmutable::setTestNow($at);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(bool $wholesale = false): array
    {
        $buyer = $wholesale
            ? BuyerContext::account(User::factory()->create(['customer_id' => Customer::factory()->wholesale(10)->create()->id])->id)
            : BuyerContext::guest(str_repeat('a', 64));
        $offer = Offer::factory()->create(['article' => 'CAMRY-70', 'price_retail' => '33.35', 'price_wholesale' => '20.00',
            'stock_qty' => 10, 'auto_checkout_enabled' => true, 'stock_verified_at' => now(), 'price_valid_until' => now()->addHours(2)]);
        $point = ServicePoint::factory()->create(['city' => 'Караганда']);
        DB::table('offer_fulfillment_points')->insert(['offer_id' => $offer->id, 'service_point_id' => $point->id, 'pickup_enabled' => true, 'installation_enabled' => true]);
        $item = ['line_id' => (string) Str::uuid(), 'offer_id' => $offer->id, 'quantity' => 3,
            'selection_mode' => 'exact_article', 'article_confirmed' => true, 'manufacturer_confirmed' => true];
        $draft = app(DraftService::class)->create($buyer, $wholesale ? SalesMode::Wholesale : SalesMode::Retail);
        $draft = app(DraftService::class)->update($buyer, $draft->id, 1, [
            'items' => [$item], 'contacts' => ['name' => 'Данил', 'phone' => '+7 700 123-45-67'],
            'fulfillment' => ['type' => 'pickup', 'city' => 'Караганда', 'service_point_id' => $point->id],
        ]);

        return compact('buyer', 'offer', 'point', 'item', 'draft');
    }

    private function prepare(array $fixture): array
    {
        return app(QuoteDraft::class)->prepare($fixture['buyer'], $fixture['draft']->id, $fixture['draft']->version)->jsonSerialize();
    }
}
