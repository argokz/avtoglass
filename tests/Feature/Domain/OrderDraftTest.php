<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\SalesMode;
use App\Domain\Shared\PurchaseException;
use App\Enums\WholesaleStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderDraftTest extends TestCase
{
    use RefreshDatabase;

    private function guest(string $seed = 'a'): BuyerContext
    {
        return BuyerContext::guest(str_repeat($seed, 64));
    }

    private function service(): DraftService
    {
        return app(DraftService::class);
    }

    private function failure(callable $operation, string $code, int $status): PurchaseException
    {
        try {
            $operation();
            $this->fail('Expected a purchase failure.');
        } catch (PurchaseException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);

            return $exception;
        }
    }

    private function item(?Offer $offer = null, array $changes = []): array
    {
        return array_replace(['line_id' => (string) Str::uuid(), 'offer_id' => $offer?->id,
            'reference' => $offer?->article ?? 'UNKNOWN-ARTICLE', 'quantity' => 2], $changes);
    }

    public function test_draft_is_persisted_with_encrypted_personal_data_and_public_dto(): void
    {
        $offer = Offer::factory()->create(['article' => 'CAMRY-70', 'price_wholesale' => '12345.67']);
        $draft = $this->service()->create($this->guest());
        $this->assertTrue(Str::isUuid($draft->id));
        $this->assertSame(1, $draft->version);
        $updated = $this->service()->update($this->guest(), $draft->id, 1, [
            'items' => [$this->item($offer)], 'locale' => 'kk',
            'contacts' => ['name' => 'Данил', 'phone' => '+77001234567'],
            'vehicle' => ['vin' => 'TESTVIN1234567890', 'options' => ['camera' => null, 'rain_sensor' => false]],
            'fulfillment' => ['type' => 'delivery', 'address' => 'Тестовая улица, 12'],
        ]);
        // Another service/context reads the saved record without relying on a session object.
        $data = (new DraftService)->get($this->guest(), $draft->id)->jsonSerialize();
        $this->assertSame($updated->jsonSerialize(), $data);
        $this->assertSame(2, $data['version']);
        $this->assertSame('kk', $data['locale']);
        $this->assertSame('Данил', $data['contacts']['name']);
        $this->assertNull($data['vehicle']['options']['camera']);
        $this->assertFalse($data['vehicle']['options']['rain_sensor']);
        $this->assertSame($offer->product->name, $data['items'][0]['name']);
        $this->assertSame($offer->supplier->name, $data['items'][0]['manufacturer']);
        $this->assertSame('CAMRY-70', $data['items'][0]['article']);
        $this->assertIsInt($data['items'][0]['offer_id']);
        $raw = DB::table('order_drafts')->where('id', $draft->id)->first();
        $this->assertSame(hash('sha256', str_repeat('a', 64)), $raw->guest_owner_hash);
        $this->assertNull($raw->customer_id);
        $this->assertStringNotContainsString('+77001234567', $raw->contacts);
        $this->assertStringNotContainsString('TESTVIN', $raw->vehicle);
        $this->assertStringNotContainsString('Тестовая', $raw->fulfillment);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['customer_id', 'guest_owner_hash', 'price_wholesale', '12345.67', $raw->guest_owner_hash] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_owner_is_checked_before_version_or_validation_and_phone_does_not_grant_access(): void
    {
        $owner = User::factory()->create(['customer_id' => Customer::factory()->wholesale()->create()->id]);
        $other = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $ownerContext = BuyerContext::account($owner->id);
        $private = $this->service()->create($ownerContext);
        $guestDraft = $this->service()->create($this->guest());
        $this->service()->update($this->guest(), $guestDraft->id, 1, ['contacts' => ['phone' => $owner->customer->phone]]);
        foreach ([$this->guest(), BuyerContext::account($other->id)] as $intruder) {
            $this->failure(fn () => $this->service()->get($intruder, $private->id), 'RESOURCE_NOT_FOUND', 404);
            $this->failure(fn () => $this->service()->update($intruder, $private->id, -1, ['customer_id' => $owner->customer_id]), 'RESOURCE_NOT_FOUND', 404);
        }
        $this->failure(fn () => $this->service()->get($this->guest('b'), $guestDraft->id), 'RESOURCE_NOT_FOUND', 404);
        $this->failure(fn () => $this->service()->get($ownerContext, $guestDraft->id), 'RESOURCE_NOT_FOUND', 404);
        $this->failure(fn () => $this->service()->get($this->guest(), 'not-a-uuid'), 'RESOURCE_NOT_FOUND', 404);
        $this->failure(fn () => $this->service()->get($this->guest(), (string) Str::uuid()), 'RESOURCE_NOT_FOUND', 404);
        $this->failure(fn () => $this->service()->create($this->guest(), SalesMode::Wholesale), 'WHOLESALE_REQUIRED', 403);
        $this->assertSame(1, $this->service()->get($ownerContext, $private->id)->version);
    }

    public function test_account_context_refreshes_approval_discount_and_account_status(): void
    {
        $customer = Customer::factory()->wholesale(10)->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $buyer = BuyerContext::account($user->id, 'kk');
        $this->assertSame(1000, $buyer->discountBasisPoints);
        $draft = $this->service()->create($buyer, SalesMode::Wholesale);
        $this->assertSame('wholesale', $draft->jsonSerialize()['sales_mode']);
        $customer->update(['wholesale_discount_percent' => 20]);
        $this->assertSame(2000, $buyer->fresh()->discountBasisPoints);
        $customer->update(['wholesale_status' => WholesaleStatus::Pending]);
        $this->assertFalse($buyer->fresh()->wholesaleApproved);
        $this->assertSame(0, $buyer->fresh()->discountBasisPoints);
        $this->failure(fn () => $this->service()->create($buyer, SalesMode::Wholesale), 'WHOLESALE_REQUIRED', 403);
        $this->failure(fn () => $this->service()->update($buyer, $draft->id, 1, ['contacts' => ['name' => 'Changed']]), 'WHOLESALE_REQUIRED', 403);
        // Revocation leaves the owner able to read their draft, but not make wholesale changes.
        $this->assertSame(1, $this->service()->get($buyer, $draft->id)->version);
        $user->update(['is_active' => false]);
        $this->failure(fn () => $this->service()->get($buyer, $draft->id), 'AUTH_REQUIRED', 401);
        $this->failure(fn () => BuyerContext::account(User::factory()->manager()->create(['customer_id' => $customer->id])->id), 'AUTH_REQUIRED', 401);
        $this->failure(fn () => BuyerContext::account(User::factory()->create()->id), 'AUTH_REQUIRED', 401);
    }

    public function test_stale_changes_cannot_overwrite_saved_items_or_contacts(): void
    {
        $draft = $this->service()->create($this->guest());
        $first = $this->service()->update($this->guest(), $draft->id, 1, [
            'items' => [$this->item()], 'contacts' => ['name' => 'First'],
        ]);
        $error = $this->failure(fn () => $this->service()->update($this->guest(), $draft->id, 1, [
            'items' => [], 'contacts' => ['name' => 'Stale'],
        ]), 'DRAFT_VERSION_CONFLICT', 409);
        $this->assertSame(['current_version' => 2], $error->details);
        $this->assertSame($first->jsonSerialize(), $this->service()->get($this->guest(), $draft->id)->jsonSerialize());
        $next = $this->service()->update($this->guest(), $draft->id, 2, ['contacts' => ['phone' => '+77000000000']]);
        $this->assertSame(['name' => 'First', 'phone' => '+77000000000'], $next->jsonSerialize()['contacts']);
        $this->assertCount(1, $next->jsonSerialize()['items']);
        $cleared = $this->service()->update($this->guest(), $draft->id, 3, ['items' => [], 'contacts' => ['name' => null]]);
        $this->assertSame([], $cleared->jsonSerialize()['items']);
        $this->assertNull($cleared->jsonSerialize()['contacts']['name']);
        $this->assertSame('+77000000000', $cleared->jsonSerialize()['contacts']['phone']);
    }

    public function test_deleted_offers_become_unresolved_without_losing_line_or_snapshot(): void
    {
        $offer = Offer::factory()->create(['article' => 'OLD-CAMRY']);
        $draft = $this->service()->create($this->guest());
        $item = $this->item($offer, ['reference' => 'Customer spreadsheet row']);
        $saved = $this->service()->update($this->guest(), $draft->id, 1, ['items' => [$item]])->jsonSerialize();
        $offer->delete();
        $deleted = $this->service()->get($this->guest(), $draft->id)->jsonSerialize()['items'][0];
        $this->assertNull($deleted['offer_id']);
        $this->assertSame(['UNRESOLVED_ITEM'], $deleted['validation_issues']);
        $this->assertSame('OLD-CAMRY', $deleted['article']);
        $this->assertSame($saved['items'][0]['name'], $deleted['name']);
        $item['offer_id'] = null;
        $item['quantity'] = 5;
        $changed = $this->service()->update($this->guest(), $draft->id, 2, ['items' => [$item]])->jsonSerialize()['items'][0];
        $this->assertSame($deleted['line_id'], $changed['line_id']);
        $this->assertSame($deleted['manufacturer'], $changed['manufacturer']);
        $this->assertSame($deleted['name'], $changed['name']);
        $this->assertSame($deleted['article'], $changed['article']);
        $this->assertSame(5, $changed['quantity']);
    }

    public function test_update_response_matches_storage_when_the_clock_crosses_a_second_during_save(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $at = CarbonImmutable::parse('2026-09-21T04:39:51+00:00');
        $this->travelTo($at);
        $draft = $this->service()->create($this->guest());
        $dateFactory = Date::getFacadeRoot();
        // An unchanged second is absent from Eloquent's dirty attributes. Its SQL builder
        // can then generate a newer updated_at before sending the UPDATE to PostgreSQL.
        Date::partialMock()->shouldReceive('now')->times(3)->andReturn($at, $at, $at->addSecond());
        try {
            $saved = $this->service()->update($this->guest(), $draft->id, 1, ['contacts' => ['name' => 'Saved']]);
        } finally {
            Date::swap($dateFactory);
            $this->travelBack();
        }
        $this->assertSame('2026-09-21T04:39:52+00:00', $saved->jsonSerialize()['updated_at']);
        $this->assertSame($saved->jsonSerialize(), $this->service()->get($this->guest(), $draft->id)->jsonSerialize());
    }

    public function test_inactive_offers_may_be_retained_but_cannot_be_newly_added(): void
    {
        $offer = Offer::factory()->create();
        $draft = $this->service()->create($this->guest());
        $item = $this->item($offer);
        $this->service()->update($this->guest(), $draft->id, 1, ['items' => [$item]]);
        $offer->update(['is_active' => false]);
        $this->assertSame(3, $this->service()->update($this->guest(), $draft->id, 2, ['items' => [$item]])->version);
        try {
            $this->service()->update($this->guest(), $draft->id, 3, ['items' => [$this->item($offer)]]);
            $this->fail('New unavailable selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['OFFER_UNAVAILABLE'], $exception->errors()['items.0.offer_id']);
        }
        $this->assertSame($item['line_id'], $this->service()->get($this->guest(), $draft->id)->jsonSerialize()['items'][0]['line_id']);
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_updates_are_atomic_and_do_not_advance_version(array $changes, string $field): void
    {
        $draft = $this->service()->create($this->guest());
        $saved = $this->service()->update($this->guest(), $draft->id, 1, ['items' => [$this->item()], 'contacts' => ['name' => 'Saved']]);
        try {
            $this->service()->update($this->guest(), $draft->id, 2, $changes);
            $this->fail('Invalid update was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertSame($saved->jsonSerialize(), $this->service()->get($this->guest(), $draft->id)->jsonSerialize());
    }

    public static function invalidChanges(): array
    {
        $line = ['line_id' => '018f26d4-5523-7000-8111-000000000001', 'reference' => 'X', 'quantity' => 1];

        return [
            'no changes' => [[], '_input'],
            'owner injection' => [['customer_id' => 1], '_input'],
            'phone privilege injection' => [['contacts' => ['name' => 'X', 'wholesale_status' => 'wholesale']], 'contacts'],
            'price injection' => [['items' => [array_replace($line, ['unit_price' => 1])]], 'items.0'],
            'fractional quantity' => [['items' => [array_replace($line, ['quantity' => 1.5])]], 'items.0.quantity'],
            'numeric string' => [['items' => [array_replace($line, ['quantity' => '2'])]], 'items.0.quantity'],
            'zero quantity' => [['items' => [array_replace($line, ['quantity' => 0])]], 'items.0.quantity'],
            'retail quantity limit' => [['items' => [array_replace($line, ['quantity' => 21])]], 'items.0.quantity'],
            'large quantity' => [['items' => [array_replace($line, ['quantity' => 100])]], 'items.0.quantity'],
            'blank offer' => [['items' => [array_replace($line, ['offer_id' => ''])]], 'items.0.offer_id'],
            'blank confirmation' => [['items' => [array_replace($line, ['article_confirmed' => ''])]], 'items.0.article_confirmed'],
            'null confirmation' => [['items' => [array_replace($line, ['article_confirmed' => null])]], 'items.0.article_confirmed'],
            'blank items' => [['items' => ''], 'items'],
            'blank contacts' => [['contacts' => ''], 'contacts'],
            'blank vehicle' => [['vehicle' => ''], 'vehicle'],
            'blank options' => [['vehicle' => ['options' => '']], 'vehicle.options'],
            'blank flag' => [['vehicle' => ['options' => ['camera' => '']]], 'vehicle.options.camera'],
            'blank mode' => [['fulfillment' => ['type' => '']], 'fulfillment.type'],
            'blank locale' => [['locale' => ''], 'locale'],
            'duplicate lines' => [['items' => [$line, $line]], 'items.0.line_id'],
            'unresolved without reference' => [['items' => [array_replace($line, ['reference' => '   '])]], 'items.0.reference'],
            'unknown option' => [['vehicle' => ['options' => ['fake_camera' => true]]], 'vehicle.options'],
            'missing offer' => [['items' => [array_replace($line, ['offer_id' => 9223372036854775807])]], 'items.0.offer_id'],
        ];
    }

    public function test_duplicate_offer_and_unpublished_product_are_rejected_without_partial_save(): void
    {
        $offer = Offer::factory()->create();
        $draft = $this->service()->create($this->guest());
        foreach ([[$this->item($offer), $this->item($offer)], [$this->item(Offer::factory()->for(Product::factory()->unpublished())->create())]] as $items) {
            try {
                $this->service()->update($this->guest(), $draft->id, 1, ['contacts' => ['name' => 'Rejected'], 'items' => $items]);
                $this->fail('Invalid catalogue selection was accepted.');
            } catch (ValidationException) {
                $this->assertSame($draft->jsonSerialize(), $this->service()->get($this->guest(), $draft->id)->jsonSerialize());
            }
        }
    }

    public function test_quantity_limits_follow_sales_mode_and_can_be_lowered_in_configuration(): void
    {
        $account = BuyerContext::account(User::factory()->create(['customer_id' => Customer::factory()->wholesale()->create()->id])->id);
        foreach ([[$this->guest(), SalesMode::Retail, 20, 5], [$account, SalesMode::Wholesale, 99, 50]] as [$buyer, $mode, $initialLimit, $newLimit]) {
            $draft = $this->service()->create($buyer, $mode);
            $saved = $this->service()->update($buyer, $draft->id, 1, ['items' => [$this->item(changes: ['quantity' => $initialLimit])]]);
            $this->assertSame($initialLimit, $saved->jsonSerialize()['items'][0]['quantity']);
            config(['ordering.limits.'.$mode->value.'_max_quantity' => $newLimit]);
            try {
                $this->service()->update($buyer, $draft->id, 2, ['items' => [$this->item(changes: ['quantity' => $newLimit + 1])]]);
                $this->fail('Configured quantity limit was ignored.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items.0.quantity', $exception->errors());
            }
            $this->assertSame(2, $this->service()->get($buyer, $draft->id)->version);
        }
        config(['ordering.limits.max_draft_items' => 1]);
        $draft = $this->service()->create($this->guest());
        try {
            $this->service()->update($this->guest(), $draft->id, 1, ['items' => [$this->item(), $this->item()]]);
            $this->fail('Configured row limit was ignored.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
    }

    public function test_database_enforces_exactly_one_owner_and_terminal_draft_is_not_editable(): void
    {
        $draft = $this->service()->create($this->guest());
        foreach ([['guest_owner_hash' => null], ['customer_id' => Customer::factory()->create()->id], ['guest_owner_hash' => 'bad']] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('order_drafts')->where('id', $draft->id)->update($invalid));
                $this->fail('The database accepted an invalid owner.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
        OrderDraft::findOrFail($draft->id)->forceFill(['status' => 'submitted'])->save();
        $this->failure(fn () => $this->service()->update($this->guest(), $draft->id, 1, ['items' => []]), 'DRAFT_NOT_EDITABLE', 409);
    }

    public function test_five_hundred_rows_use_bounded_catalogue_queries_and_limit_is_enforced(): void
    {
        $product = Product::factory()->create();
        $supplier = Supplier::factory()->create();
        $offers = Offer::factory()->count(500)->create(['product_id' => $product->id, 'supplier_id' => $supplier->id]);
        $items = $offers->map(fn ($offer) => $this->item($offer))->all();
        $draft = $this->service()->create($this->guest());
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $saved = $this->service()->update($this->guest(), $draft->id, 1, ['items' => $items]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(500, $saved->jsonSerialize()['items']);
        $this->assertLessThanOrEqual(12, count($queries), 'Saving 500 rows must not query each offer separately.');
        try {
            $this->service()->update($this->guest(), $draft->id, 2, ['items' => [...$items, $this->item()]]);
            $this->fail('The draft exceeded 500 rows.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }
        $this->assertSame(2, $this->service()->get($this->guest(), $draft->id)->version);
        $this->assertDatabaseCount('order_draft_items', 500);
    }
}
