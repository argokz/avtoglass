<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Ordering\SalesMode;
use App\Domain\Shared\Commands\CommandResult;
use App\Domain\Shared\Commands\Idempotency;
use App\Domain\Shared\Commands\IdempotencyRecord;
use App\Domain\Shared\PurchaseException;
use App\Enums\WholesaleStatus;
use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(string $seed = 'a'): BuyerContext
    {
        return BuyerContext::guest(str_repeat($seed, 64));
    }

    public function test_repeated_create_returns_original_data_and_status_without_a_second_draft(): void
    {
        $commands = app(PurchaseCommands::class);
        $first = $commands->createDraft($this->buyer(), 'create-1');
        $repeat = $commands->createDraft($this->buyer(), 'create-1');
        $this->assertFalse($first->replayed);
        $this->assertTrue($repeat->replayed);
        $this->assertSame(201, $repeat->status);
        $this->assertSame($first->data, $repeat->data);
        $this->assertDatabaseCount('order_drafts', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
        $raw = DB::table('idempotency_records')->first();
        $this->assertNotSame('create-1', $raw->key_hash);
        $this->assertStringNotContainsString($first->data['id'], $raw->response_payload);
    }

    public function test_repeated_update_returns_original_snapshot_without_reverting_newer_changes(): void
    {
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($this->buyer(), 'same-key');
        $first = $commands->updateDraft($this->buyer(), $draft->data['id'], 'same-key', 1, ['contacts' => ['name' => 'First', 'phone' => '+77001234567']]);
        $commands->updateDraft($this->buyer(), $draft->data['id'], 'later', 2, ['contacts' => ['name' => 'Later']]);
        $repeat = $commands->updateDraft($this->buyer(), $draft->data['id'], 'same-key', 1, ['contacts' => ['phone' => '+77001234567', 'name' => 'First']]);
        $this->assertTrue($repeat->replayed);
        $this->assertSame($first->data, $repeat->data);
        $current = app(DraftService::class)->get($this->buyer(), $draft->data['id'])->jsonSerialize();
        $this->assertSame(3, $current['version']);
        $this->assertSame('Later', $current['contacts']['name']);
        $this->assertDatabaseCount('idempotency_records', 3);
    }

    public function test_repeated_quote_does_not_allocate_another_quote_version(): void
    {
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($this->buyer(), 'draft');
        $first = $commands->prepareQuote($this->buyer(), $draft->data['id'], 'quote', 1);
        $repeat = $commands->prepareQuote($this->buyer(), $draft->data['id'], 'quote', 1);
        $this->assertTrue($repeat->replayed);
        $this->assertSame($first->data, $repeat->data);
        $this->assertDatabaseCount('checkout_quotes', 1);
    }

    public function test_changed_payload_with_the_same_key_conflicts_without_mutation(): void
    {
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($this->buyer(), 'draft');
        $commands->updateDraft($this->buyer(), $draft->data['id'], 'update', 1, ['contacts' => ['name' => 'First']]);
        try {
            $commands->updateDraft($this->buyer(), $draft->data['id'], 'update', 1, ['contacts' => ['name' => 'Different']]);
            $this->fail('Different input reused an existing key.');
        } catch (PurchaseException $exception) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame('First', app(DraftService::class)->get($this->buyer(), $draft->data['id'])->jsonSerialize()['contacts']['name']);
    }

    public function test_owner_scopes_are_distinct_and_foreign_drafts_are_not_replayed(): void
    {
        $commands = app(PurchaseCommands::class);
        $first = $commands->createDraft($this->buyer(), 'draft');
        $other = $commands->createDraft($this->buyer('b'), 'draft');
        $this->assertNotSame($first->data['id'], $other->data['id']);
        $this->expectExceptionMessage('RESOURCE_NOT_FOUND');
        $commands->updateDraft($this->buyer('b'), $first->data['id'], 'update', 1, ['items' => []]);
    }

    public function test_failed_transaction_rolls_back_the_mutation_and_does_not_consume_the_key(): void
    {
        $store = app(Idempotency::class);
        try {
            $store->execute($this->buyer(), 'test.rollback', 'key', [], fn () => null, function () {
                app(DraftService::class)->create($this->buyer());
                throw new RuntimeException('Simulated failure after the mutation.');
            });
            $this->fail('Expected rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure after the mutation.', $exception->getMessage());
        }
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $success = $store->execute($this->buyer(), 'test.rollback', 'key', [], fn () => null,
            fn () => new CommandResult(app(DraftService::class)->create($this->buyer())->jsonSerialize(), 201));
        $this->assertFalse($success->replayed);
        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_validation_error_can_be_corrected_using_the_unconsumed_key(): void
    {
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($this->buyer(), 'draft');
        try {
            $commands->updateDraft($this->buyer(), $draft->data['id'], 'update', 1, ['price' => 1]);
            $this->fail('Invalid fields were accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('idempotency_records', 1);
        }
        $this->assertSame(2, $commands->updateDraft($this->buyer(), $draft->data['id'], 'update', 1, ['contacts' => ['name' => 'Valid']])->data['version']);
    }

    public function test_expired_response_is_pruned_but_the_key_cannot_execute_again(): void
    {
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($this->buyer(), 'draft');
        IdempotencyRecord::sole()->forceFill(['expires_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
        $this->assertSame(1, app(Idempotency::class)->pruneExpiredPayloads());
        $this->assertNull(IdempotencyRecord::sole()->response_payload);
        try {
            $commands->createDraft($this->buyer(), 'draft');
            $this->fail('An expired key created another draft.');
        } catch (PurchaseException $exception) {
            $this->assertSame('IDEMPOTENCY_RESULT_EXPIRED', $exception->errorCode);
            $this->assertSame(['result_id' => $draft->data['id']], $exception->details);
        }
        $this->assertDatabaseCount('order_drafts', 1);
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function test_cached_wholesale_command_still_requires_current_approval(): void
    {
        $customer = Customer::factory()->wholesale()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $buyer = BuyerContext::account($user->id);
        app(PurchaseCommands::class)->createDraft($buyer, 'wholesale', SalesMode::Wholesale);
        $customer->update(['wholesale_status' => WholesaleStatus::Retail]);
        $this->expectExceptionMessage('WHOLESALE_REQUIRED');
        app(PurchaseCommands::class)->createDraft($buyer, 'wholesale', SalesMode::Wholesale);
    }

    public function test_cleanup_command_respects_feature_flag_and_retains_key_tombstones(): void
    {
        app(PurchaseCommands::class)->createDraft($this->buyer(), 'draft');
        IdempotencyRecord::sole()->forceFill(['expires_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
        config(['ordering.api_enabled' => false]);
        $this->artisan('ordering:prune-idempotency')->assertSuccessful();
        $this->assertNotNull(IdempotencyRecord::sole()->response_payload);
        config(['ordering.api_enabled' => true]);
        $this->artisan('ordering:prune-idempotency')->assertSuccessful();
        $this->assertNull(IdempotencyRecord::sole()->response_payload);
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertDatabaseCount('order_drafts', 1);
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_rejected_before_creating_records(string $key): void
    {
        $this->expectException(ValidationException::class);
        app(PurchaseCommands::class)->createDraft($this->buyer(), $key);
    }

    public static function invalidKeys(): array
    {
        return [[''], ['space key'], [str_repeat('a', 129)], ['ключ']];
    }
}
