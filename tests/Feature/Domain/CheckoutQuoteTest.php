<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Pricing\Models\CheckoutQuote;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\PurchaseException;
use App\Enums\WholesaleStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsQuoteFixtures;
use Tests\TestCase;

class CheckoutQuoteTest extends TestCase
{
    use BuildsQuoteFixtures, RefreshDatabase;

    public function test_quote_has_its_own_version_exact_totals_expiry_and_no_order_side_effects(): void
    {
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $this->assertTrue(Str::isUuid($quote['id']));
        $this->assertSame(1, $quote['version']);
        $this->assertSame(2, $quote['draft_version']);
        $this->assertSame('ready', $quote['readiness']);
        $this->assertSame([], $quote['reasons']);
        $this->assertSame(['amount_minor' => 10005, 'currency' => 'KZT'], $quote['total']);
        $this->assertSame(0, $quote['services_total']['amount_minor']);
        $this->assertSame(0, $quote['delivery_total']['amount_minor']);
        $this->assertSame('exact_article', $quote['items'][0]['fitment']['status']);
        $this->assertSame('2026-09-20T05:15:00+00:00', $quote['expires_at']);
        $this->assertSame(10, $quote['items'][0]['available_quantity']);
        $this->assertSame($quote, app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $quote['id'])->jsonSerialize());
        $this->assertSame(2, app(DraftService::class)->get($f['buyer'], $f['draft']->id)->version);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('assignments', 0);
        $raw = DB::table('checkout_quotes')->where('id', $quote['id'])->first();
        $this->assertStringNotContainsString('77001234567', $raw->snapshot);
        $json = json_encode($quote, JSON_THROW_ON_ERROR);
        foreach (['source_hash', 'price_wholesale', 'discount_basis_points', 'guest_owner_hash', 'created_by', 'customer_id', $raw->source_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_wholesale_price_rounds_per_unit_and_always_requires_manager(): void
    {
        $f = $this->fixture(true);
        $quote = $this->prepare($f);
        $this->assertSame(3002, $quote['items'][0]['unit_price']['amount_minor']);
        $this->assertSame(9006, $quote['total']['amount_minor']);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertSame(['WHOLESALE_REVIEW_REQUIRED'], $quote['reasons']);
        $f['offer']->update(['price_wholesale' => '30.03']);
        $this->assertSame(9009, $this->prepare($f)['total']['amount_minor']);
        $f['offer']->update(['price_wholesale' => null]);
        $unknown = $this->prepare($f);
        $this->assertNull($unknown['total']);
        $this->assertContains('PRICE_UNCONFIRMED', $unknown['reasons']);
    }

    public function test_unknown_price_is_not_zero_and_does_not_erase_known_lines(): void
    {
        $f = $this->fixture();
        $unknown = Offer::factory()->create(['price_retail' => null]);
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, 2, ['items' => [
            $f['item'], ['line_id' => (string) Str::uuid(), 'offer_id' => $unknown->id, 'quantity' => 1],
        ]]);
        $quote = $this->prepare($f);
        $this->assertNull($quote['items'][1]['unit_price']);
        $this->assertNull($quote['items'][1]['total']);
        $this->assertNull($quote['goods_total']);
        $this->assertNull($quote['total']);
        $this->assertSame(10005, $quote['known_total']['amount_minor']);
        $this->assertContains('PRICE_UNCONFIRMED', $quote['reasons']);
    }

    #[DataProvider('unreadyOffers')]
    public function test_unconfirmed_catalogue_facts_never_allow_automatic_confirmation(array $changes, string $reason): void
    {
        $f = $this->fixture();
        $f['offer']->update($changes);
        $quote = $this->prepare($f);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertContains($reason, $quote['reasons']);
    }

    public static function unreadyOffers(): array
    {
        return [
            [['auto_checkout_enabled' => false], 'AUTO_CHECKOUT_DISABLED'],
            [['price_valid_until' => null], 'PRICE_UNCONFIRMED'],
            [['price_valid_until' => '2026-09-20T05:00:00Z'], 'PRICE_UNCONFIRMED'],
            [['stock_verified_at' => null], 'STOCK_UNCONFIRMED'],
            [['stock_verified_at' => '2026-09-19T04:59:59Z'], 'STOCK_UNCONFIRMED'],
            [['stock_verified_at' => '2026-09-20T05:00:01Z'], 'STOCK_UNCONFIRMED'],
            [['stock_qty' => 2], 'INSUFFICIENT_STOCK'],
            [['is_active' => false], 'OFFER_UNAVAILABLE'],
            [['price_retail' => '0.00'], 'PRICE_UNCONFIRMED'],
            [['price_retail' => '-1.00'], 'PRICE_UNCONFIRMED'],
            [['currency' => 'USD'], 'PRICE_UNCONFIRMED'],
        ];
    }

    public function test_migration_defaults_do_not_enable_existing_or_new_offers_for_automatic_sales(): void
    {
        $offer = Offer::factory()->create()->fresh();
        $this->assertFalse($offer->auto_checkout_enabled);
        $this->assertNull($offer->price_valid_until);
        $this->assertNull($offer->stock_verified_at);
    }

    public function test_held_stock_is_subtracted_even_when_expiry_job_has_not_released_it(): void
    {
        $f = $this->fixture();
        $order = Order::factory()->create();
        $item = OrderItem::query()->create(['order_id' => $order->id, 'offer_id' => $f['offer']->id, 'name' => 'Held', 'quantity' => 8, 'price' => '33.35']);
        $reservation = StockReservation::query()->create(['order_id' => $order->id, 'order_item_id' => $item->id, 'offer_id' => $f['offer']->id, 'quantity' => 8,
            'status' => 'held', 'expires_at' => now()->subHour()]);
        $quote = $this->prepare($f);
        $this->assertSame(2, $quote['items'][0]['available_quantity']);
        $this->assertContains('INSUFFICIENT_STOCK', $quote['reasons']);
        $reservation->update(['status' => 'released']);
        $this->assertSame('ready', $this->prepare($f)['readiness']);
    }

    public function test_quote_versions_preserve_old_terms_and_draft_edit_supersedes_them(): void
    {
        $f = $this->fixture();
        $first = $this->prepare($f);
        $f['offer']->update(['price_retail' => '50.00']);
        $second = $this->prepare($f);
        $this->assertSame(2, $second['version']);
        $this->assertSame(15000, $second['total']['amount_minor']);
        $old = app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $first['id'])->jsonSerialize();
        $this->assertSame(10005, $old['total']['amount_minor']);
        $this->assertSame('superseded', $old['status']);
        $this->assertDatabaseCount('checkout_quotes', 2);
        app(DraftService::class)->update($f['buyer'], $f['draft']->id, 2, ['contacts' => ['name' => 'Changed']]);
        $this->assertSame('superseded', app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $second['id'])->jsonSerialize()['status']);
        $this->assertDatabaseCount('checkout_quotes', 2);
    }

    public function test_expired_quote_can_be_read_but_cannot_be_submitted(): void
    {
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $later = CarbonImmutable::parse($quote['expires_at']);
        $this->travelTo($later);
        CarbonImmutable::setTestNow($later);
        $this->assertSame('expired', app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $quote['id'])->jsonSerialize()['status']);
        $this->expectExceptionMessage('QUOTE_EXPIRED');
        DB::transaction(fn () => app(QuoteDraft::class)->assertCurrent($f['buyer'], $f['draft']->id, $quote['id'], 2));
    }

    #[DataProvider('changedSources')]
    public function test_revalidation_detects_changes_without_mutating_accepted_terms(string $change): void
    {
        $f = $this->fixture(true);
        $quote = $this->prepare($f);
        $this->assertSame($quote['id'], DB::transaction(fn () => app(QuoteDraft::class)->assertCurrent($f['buyer'], $f['draft']->id, $quote['id'], 2))->id);
        match ($change) {
            'price' => $f['offer']->update(['price_retail' => '50.00']),
            'stock' => $f['offer']->update(['stock_qty' => 9]),
            'discount' => Customer::whereKey($f['buyer']->customerId)->update(['wholesale_discount_percent' => 11]),
            'point' => $f['point']->update(['is_active' => false]),
            'supplier' => $f['offer']->supplier->update(['is_active' => false]),
            'fitment-policy' => config(['ordering.fitment.required_options.windshield' => ['camera']]),
        };
        try {
            DB::transaction(fn () => app(QuoteDraft::class)->assertCurrent($f['buyer'], $f['draft']->id, $quote['id'], 2));
            $this->fail('Changed conditions were accepted.');
        } catch (PurchaseException $exception) {
            $this->assertSame('QUOTE_CHANGED', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame($quote['total'], app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $quote['id'])->jsonSerialize()['total']);
        $this->assertDatabaseCount('checkout_quotes', 1);
    }

    public static function changedSources(): array
    {
        return array_map(fn ($source) => [$source], ['price', 'stock', 'discount', 'point', 'supplier', 'fitment-policy']);
    }

    public function test_other_owner_cannot_read_prepare_or_revalidate_quote(): void
    {
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $intruder = BuyerContext::guest(str_repeat('b', 64));
        foreach ([
            fn () => app(QuoteDraft::class)->get($intruder, $f['draft']->id, $quote['id']),
            fn () => app(QuoteDraft::class)->prepare($intruder, $f['draft']->id, -1),
            fn () => DB::transaction(fn () => app(QuoteDraft::class)->assertCurrent($intruder, $f['draft']->id, $quote['id'], -1)),
            fn () => app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, 'bad-id'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected owner-scoped not found.');
            } catch (PurchaseException $exception) {
                $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
                $this->assertSame(404, $exception->status);
            }
        }
        $this->assertDatabaseCount('checkout_quotes', 1);
    }

    public function test_wholesale_revocation_prevents_preparation_from_stale_buyer_context(): void
    {
        $f = $this->fixture(true);
        Customer::whereKey($f['buyer']->customerId)->update(['wholesale_status' => WholesaleStatus::Retail]);
        $this->expectExceptionMessage('WHOLESALE_REQUIRED');
        $this->prepare($f);
    }

    public function test_immutable_quote_cannot_be_repriced_in_place(): void
    {
        $f = $this->fixture();
        $quote = CheckoutQuote::findOrFail($this->prepare($f)['id']);
        $this->expectException(LogicException::class);
        $quote->forceFill(['snapshot' => ['total' => 1]])->save();
    }

    public function test_empty_and_incomplete_draft_is_persistable_but_not_ready(): void
    {
        $buyer = BuyerContext::guest(str_repeat('a', 64));
        $draft = app(DraftService::class)->create($buyer);
        $quote = app(QuoteDraft::class)->prepare($buyer, $draft->id, 1)->jsonSerialize();
        $this->assertSame('incomplete', $quote['readiness']);
        foreach (['EMPTY_DRAFT', 'CONTACT_NAME_REQUIRED', 'CONTACT_PHONE_REQUIRED', 'CITY_REQUIRED', 'SERVICE_POINT_REQUIRED'] as $reason) {
            $this->assertContains($reason, $quote['reasons']);
        }
    }

    public function test_quote_and_offer_timestamps_preserve_the_instant_across_database_timezones(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $f = $this->fixture();
        $quote = $this->prepare($f);
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $saved = app(QuoteDraft::class)->get($f['buyer'], $f['draft']->id, $quote['id'])->jsonSerialize();
        $this->assertSame('2026-09-20T05:15:00+00:00', $saved['expires_at']);
        $this->assertSame('2026-09-20T05:00:00+00:00', $saved['created_at']);
        $this->assertSame('2026-09-20T05:00:00+00:00', $f['offer']->fresh()->stock_verified_at->toIso8601String());
        $this->assertSame('2026-09-20T07:00:00+00:00', $f['offer']->fresh()->price_valid_until->toIso8601String());
        $this->assertSame('prepared', $saved['status']);
        $this->assertSame('ready', $saved['readiness']);
        $this->assertSame('2026-09-20T05:00:00+00:00', app(DraftService::class)->get($f['buyer'], $f['draft']->id)->jsonSerialize()['updated_at']);
    }

    public function test_five_hundred_line_quote_has_bounded_queries_and_one_server_total(): void
    {
        $f = $this->fixture(true);
        $offers = Offer::factory()->count(500)->create(['product_id' => $f['offer']->product_id, 'supplier_id' => $f['offer']->supplier_id,
            'price_retail' => '33.35', 'price_wholesale' => '20.00']);
        $items = $offers->map(fn ($offer) => ['line_id' => (string) Str::uuid(), 'offer_id' => $offer->id, 'quantity' => 1])->all();
        $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, 2, ['items' => $items]);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $quote = $this->prepare($f);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(500, $quote['items']);
        $this->assertSame(1501000, $quote['total']['amount_minor']);
        $this->assertSame('review_required', $quote['readiness']);
        $this->assertLessThanOrEqual(20, $count, 'A quote must load catalogue facts and reservations in batches.');
    }
}
