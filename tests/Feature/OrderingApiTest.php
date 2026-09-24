<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftService;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ordering.api_enabled' => true]);
        $this->withSession(['ordering.guest_token' => str_repeat('a', 64)]);
    }

    private function createDraft(string $key = 'create'): array
    {
        return $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => $key])->assertCreated()->json('data');
    }

    public function test_api_is_disabled_by_default_and_does_not_create_business_records(): void
    {
        config(['ordering.api_enabled' => false]);
        $this->getJson('/api/v1/session')->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->postJson('/api/v1/drafts', [])->assertNotFound();
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_session_initialization_returns_csrf_without_owner_token_or_business_side_effects(): void
    {
        $this->withSession(['ordering.guest_token' => null]);
        $response = $this->getJson('/api/v1/session', ['X-Locale' => 'kk'])->assertOk()->assertHeader('Content-Language', 'kk')
            ->assertJsonPath('data.locale', 'kk')->assertJsonPath('data.authenticated', false);
        $token = session('ordering.guest_token');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        $this->assertNotEmpty($response->json('data.csrf_token'));
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('checkout_quotes', 0);
    }

    public function test_create_update_quote_and_read_share_one_owner_and_idempotent_results(): void
    {
        $draft = $this->createDraft();
        $repeat = $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'create'])
            ->assertCreated()->assertJsonPath('meta.replayed', true)->json('data');
        $this->assertSame($draft, $repeat);
        $url = '/api/v1/drafts/'.$draft['id'];
        $payload = ['expected_version' => 1, 'changes' => ['contacts' => ['name' => 'Данил', 'phone' => '+77001234567']]];
        $updated = $this->patchJson($url, $payload, ['Idempotency-Key' => 'update'])->assertOk()->assertJsonPath('data.version', 2)->json('data');
        $replayed = $this->patchJson($url, $payload, ['Idempotency-Key' => 'update'])->assertOk()->assertJsonPath('meta.replayed', true)->json('data');
        $this->assertSame($updated, $replayed);
        $quote = $this->postJson($url.'/quotes', ['expected_version' => 2], ['Idempotency-Key' => 'quote'])->assertCreated()->json('data');
        $this->postJson($url.'/quotes', ['expected_version' => 2], ['Idempotency-Key' => 'quote'])->assertCreated()
            ->assertJsonPath('data.id', $quote['id'])->assertJsonPath('meta.replayed', true);
        $this->getJson($url)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson($url.'/quotes/'.$quote['id'])->assertOk()->assertJsonPath('data.id', $quote['id']);
        $this->assertDatabaseCount('order_drafts', 1);
        $this->assertDatabaseCount('checkout_quotes', 1);
        $this->assertDatabaseCount('idempotency_records', 3);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_guest_must_persist_a_session_before_a_business_command(): void
    {
        $this->withSession(['ordering.guest_token' => null]);
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'first'])
            ->assertConflict()->assertJsonPath('error.code', 'SESSION_REQUIRED');
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $this->getJson('/api/v1/session')->assertOk();
        $token = session('ordering.guest_token');
        $this->createDraft('first');
        $this->getJson('/api/v1/session')->assertOk();
        $this->assertSame($token, session('ordering.guest_token'));
        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_nonpersistent_session_or_missing_lock_store_is_rejected_before_session_start(): void
    {
        foreach (['cookie', null] as $driver) {
            config(['session.driver' => $driver]);
            $this->getJson('/api/v1/session')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        }
        config(['session.driver' => 'array', 'session.block_store' => 'missing']);
        $this->getJson('/api/v1/session')->assertStatus(503)->assertJsonPath('error.code', 'DEPENDENCY_UNAVAILABLE');
        config(['ordering.api_enabled' => false]);
        $this->getJson('/api/v1/session')->assertNotFound();
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_session_lock_timeout_returns_retry_instruction_without_debug_details(): void
    {
        Route::get('/api/v1/test-busy', fn () => throw new LockTimeoutException('private-lock-key'));
        $response = $this->getJson('/api/v1/test-busy', ['X-Locale' => 'kk'])->assertStatus(503)
            ->assertHeader('Retry-After', '1')->assertJsonPath('error.code', 'SESSION_BUSY');
        $this->assertStringNotContainsString('private-lock-key', $response->getContent());
    }

    public function test_conflicting_body_and_stale_version_have_different_errors(): void
    {
        $draft = $this->createDraft();
        $url = '/api/v1/drafts/'.$draft['id'];
        $this->patchJson($url, ['expected_version' => 1, 'changes' => ['contacts' => ['name' => 'First']]], ['Idempotency-Key' => 'u'])->assertOk();
        $this->patchJson($url, ['expected_version' => 1, 'changes' => ['contacts' => ['name' => 'Other']]], ['Idempotency-Key' => 'u'])
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->patchJson($url, ['expected_version' => 1, 'changes' => ['contacts' => ['name' => 'Other']]], ['Idempotency-Key' => 'new-key'])
            ->assertConflict()->assertJsonPath('error.code', 'DRAFT_VERSION_CONFLICT')->assertJsonPath('error.current_version', 2);
    }

    public function test_owner_and_prices_cannot_be_injected_through_json(): void
    {
        $this->postJson('/api/v1/drafts', ['customer_id' => 1, 'sales_mode' => 'retail'], ['Idempotency-Key' => 'bad'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $draft = $this->createDraft();
        $this->patchJson('/api/v1/drafts/'.$draft['id'], ['expected_version' => 1, 'changes' => ['price' => 1]], ['Idempotency-Key' => 'bad'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_unknown_and_foreign_uuid_return_the_same_envelope(): void
    {
        $foreign = app(DraftService::class)->create(BuyerContext::guest(str_repeat('b', 64)));
        foreach ([$foreign->id, (string) Str::uuid(), 'not-a-uuid'] as $id) {
            $response = $this->getJson('/api/v1/drafts/'.$id)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
            $this->assertTrue(Str::isUuid($response->json('meta.request_id')));
            $this->assertArrayNotHasKey('data', $response->json());
        }
    }

    public function test_strict_json_errors_are_localized_and_do_not_leak_debug_details(): void
    {
        $draft = $this->createDraft();
        $this->patchJson('/api/v1/drafts/'.$draft['id'], ['expected_version' => '1', 'changes' => ['items' => []]], ['Idempotency-Key' => 'bad', 'X-Locale' => 'kk'])
            ->assertUnprocessable()->assertHeader('Content-Language', 'kk')->assertJsonPath('error.fields.expected_version.0', 'Ағымдағы нұсқаның бүтін нөмірін беріңіз.');
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['X-Locale' => 'ru'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        config(['app.debug' => true]);
        Route::get('/api/v1/test-failure', fn () => throw new \RuntimeException('private-server-secret'));
        $response = $this->getJson('/api/v1/test-failure')->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('private-server-secret', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_invalid_json_media_type_and_size_are_rejected_before_mutation(): void
    {
        $this->call('POST', '/api/v1/drafts', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')
            ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_JSON');
        $this->call('POST', '/api/v1/drafts', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertStatus(400);
        $this->post('/api/v1/drafts', ['sales_mode' => 'retail'])->assertStatus(415);
        $this->call('POST', '/api/v1/drafts', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 1_048_577))->assertStatus(413);
        $this->assertDatabaseCount('order_drafts', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
    }

    public function test_real_csrf_middleware_rejects_missing_token_and_accepts_matching_session_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'create'])
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
        $token = $this->getJson('/api/v1/session')->assertOk()->json('data.csrf_token');
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'create', 'X-CSRF-TOKEN' => $token])->assertCreated();
        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_twenty_writes_per_minute_are_allowed_and_next_request_has_retry_after(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'repeat'])->assertCreated();
        }
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'retail'], ['Idempotency-Key' => 'repeat'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertDatabaseCount('order_drafts', 1);
    }

    public function test_guest_cannot_open_wholesale_and_staff_cannot_act_as_buyer(): void
    {
        $this->postJson('/api/v1/drafts', ['sales_mode' => 'wholesale'], ['Idempotency-Key' => 'wholesale'])
            ->assertForbidden()->assertJsonPath('error.code', 'WHOLESALE_REQUIRED');
        $user = User::factory()->manager()->create(['customer_id' => Customer::factory()->wholesale()->create()->id]);
        $this->actingAs($user)->getJson('/api/v1/session')->assertUnauthorized()->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertDatabaseCount('order_drafts', 0);
    }

    public function test_error_translation_keys_match_between_russian_and_kazakh(): void
    {
        $ru = require base_path('lang/ru/ordering.php');
        $kk = require base_path('lang/kk/ordering.php');
        $this->assertSame(array_keys($ru['errors']), array_keys($kk['errors']));
        $this->assertSame(array_keys($ru['validation']), array_keys($kk['validation']));
    }
}
