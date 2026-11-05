<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AssistantCheckout;
use App\Ai\AssistantDialog;
use App\Ai\AssistantTools;
use App\Ai\Providers\ChatProvider;
use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\Conversation;
use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Livewire\AiChat;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsQuoteFixtures;
use Tests\Support\BuildsVerifiedQuoteFixture;
use Tests\TestCase;

class AssistantCheckoutTest extends TestCase
{
    use BuildsQuoteFixtures, BuildsVerifiedQuoteFixture, RefreshDatabase;

    private function args(array $fixture): array
    {
        return ['name' => 'Клиент', 'phone' => '77001234567', 'offer_id' => $fixture['offer']->id, 'fulfillment' => 'pickup', 'city' => $fixture['point']->city, 'service_point_id' => $fixture['point']->id];
    }

    private function turn(BuyerContext $buyer): string
    {
        $conversation = Conversation::query()->firstOrCreate(['owner_key' => ChatIdentity::ownerKey($buyer)], [
            'id' => (string) Str::uuid(), 'actor_id' => $buyer->actorId, 'locale' => $buyer->locale,
        ]);
        $id = app(ChatStore::class)->enqueue($conversation, 'Подготовьте заявку');
        app(ChatStore::class)->claim($id);

        return $id;
    }

    private function complete(string $turn): void
    {
        app(ChatStore::class)->write($turn, 'Проверьте карточку', 'completed');
    }

    public function test_preparation_and_confirmation_retries_create_one_order_and_one_callback(): void
    {
        Queue::fake();
        settings()->save(['privacy_consent' => true]);
        $f = $this->fixture();
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $args = [...$this->args($f), 'privacy_confirmed' => true];
        $first = $service->prepare($f['buyer'], $args, $turn)['checkout'];
        $repeat = $service->prepare($f['buyer'], $args, $turn)['checkout'];
        $this->assertSame($first, $repeat);
        $this->assertSame('review_required', $first['readiness']);
        $this->assertSame('submit_for_review', $first['action']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('ai_checkouts', 1);
        $this->complete($turn);

        try {
            $service->confirm($f['buyer'], $first['id'], false, $first['quote_id']);
            $this->fail('Model consent must not submit an order.');
        } catch (PurchaseException $exception) {
            $this->assertSame('PRIVACY_CONSENT_REQUIRED', $exception->errorCode);
        }
        $result = $service->confirm($f['buyer'], $first['id'], true, $first['quote_id']);
        $again = $service->confirm($f['buyer'], $first['id'], true, $first['quote_id']);
        $this->assertSame($result, $again);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(OrderSource::Assistant, Order::query()->sole()->source);
    }

    public function test_delivery_request_is_preserved_in_quote_and_confirmed_order_without_installation(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $turn = $this->turn($f['buyer']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([['functionCall' => ['name' => 'prepare_order', 'args' => [
            ...$this->args($f), 'fulfillment' => 'installation', 'city' => 'Алматы', 'year' => 2019,
        ]]]]);
        $history = [
            ['role' => 'user', 'text' => 'Лобовое стекло RAV4 1995 года без установки доставка Караганда, куйбышева 15'],
            ['role' => 'tool_context', 'tools' => [['name' => 'search_products', 'args' => [], 'result' => ['items' => [['offer_id' => $f['offer']->id]]]]]],
            ['role' => 'user', 'text' => 'Беру offer_id '.$f['offer']->id.': Лобовое стекло RAV4 1994–2000'],
            ['role' => 'user', 'text' => 'Клиент, 77001234567'],
        ];
        $card = null;
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply($history, $f['buyer'], null, null, $turn,
            function ($name, $args, $result) use (&$card) {
                $card = $result['checkout'] ?? null;
            });
        $this->assertIsArray($card);
        $this->assertSame('delivery', $card['fulfillment']['type']);
        $this->assertSame('Караганда', $card['fulfillment']['city']);
        $this->assertSame('куйбышева 15', $card['fulfillment']['address']);
        $this->assertSame(1995, $card['vehicle']['year']);
        $this->assertSame([], $card['service_items']);
        $this->assertStringContainsString('Без установки', $card['items'][0]['comment']);
        $this->assertDatabaseCount('orders', 0);
        $this->complete($turn);
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key'], 'ai.mode' => 'gemini_only']);
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        Livewire::test(AiChat::class, ['embedded' => true])
            ->assertSee('куйбышева 15')->assertSee('Без установки.')
            ->assertSee('Стоимость доставки:')->assertSee('Караганда');
        $result = app(AssistantCheckout::class)->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
        $order = Order::query()->findOrFail($result['order_id']);
        $this->assertSame(FulfillmentType::Delivery, $order->fulfillment);
        $this->assertSame('Караганда', $order->city);
        $this->assertSame('куйбышева 15', $order->address);
        $this->assertStringContainsString('Без установки', $order->comment);
    }

    public function test_other_guest_cannot_read_refresh_or_confirm_even_submitted_checkout(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $card = $service->prepare($f['buyer'], $this->args($f), $turn)['checkout'];
        $this->complete($turn);
        $other = BuyerContext::guest(str_repeat('b', 64));
        $this->assertSame([], $service->pendingForTurns($other, [$turn]));
        foreach (['refresh', 'confirm'] as $method) {
            try {
                $method === 'refresh' ? $service->refresh($other, $card['id']) : $service->confirm($other, $card['id'], true, $card['quote_id']);
                $this->fail('A foreign checkout must be inaccessible.');
            } catch (PurchaseException $exception) {
                $this->assertSame('RESOURCE_NOT_FOUND', $exception->errorCode);
            }
        }
        $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
        $this->expectException(PurchaseException::class);
        $service->confirm($other, $card['id'], true, $card['quote_id']);
    }

    public function test_unresolved_selection_preserves_request_and_comment_for_manager(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $args = $this->args($f);
        unset($args['offer_id']);
        $args += ['reference' => 'Лобовое Camry 2020, камера неизвестна', 'comment' => 'Перезвонить после 18:00'];
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $card = $service->prepare($f['buyer'], $args, $turn)['checkout'];
        $this->complete($turn);
        $this->assertContains('UNRESOLVED_ITEM', $card['reasons']);
        $this->assertNull($card['total']);
        $result = $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
        $order = Order::query()->findOrFail($result['order_id']);
        $this->assertStringContainsString($args['reference'], $order->comment);
        $this->assertStringContainsString($args['comment'], $order->comment);
        $this->assertSame('manager_review', $result['path']);
    }

    public function test_changed_price_needs_refresh_and_new_confirmation_for_visible_quote(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $card = $service->prepare($f['buyer'], $this->args($f), $turn)['checkout'];
        $this->complete($turn);
        $f['offer']->update(['price_retail' => '45.67']);
        try {
            $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
            $this->fail('A changed price requires a new displayed quote.');
        } catch (PurchaseException $exception) {
            $this->assertSame('QUOTE_CHANGED', $exception->errorCode);
        }
        $fresh = $service->refresh($f['buyer'], $card['id']);
        $this->assertSame(4567, $fresh['items'][0]['unit_price']['amount_minor']);
        $this->assertDatabaseCount('orders', 0);
        try {
            $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
            $this->fail('An older browser tab may not approve the new quote.');
        } catch (PurchaseException $exception) {
            $this->assertSame('CHECKOUT_CHANGED', $exception->errorCode);
        }
        $service->confirm($f['buyer'], $fresh['id'], true, $fresh['quote_id']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_verified_vehicle_can_use_shared_auto_checkout_and_expired_quote_cannot_submit(): void
    {
        Queue::fake();
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->verifiedFixture();
        $vehicle = $f['vehicle'];
        $vehicle['car_model_id'] = $vehicle['model_id'];
        unset($vehicle['model_id']);
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $card = $service->prepare($f['buyer'], [...$this->args($f), ...$vehicle], $turn)['checkout'];
        $this->complete($turn);
        $this->assertSame('ready', $card['readiness']);
        $this->assertSame('confirm_retail', $card['action']);
        $this->travel(config('ordering.quote_ttl_minutes') + 1)->minutes();
        try {
            $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
            $this->fail('Expired quote must be refreshed.');
        } catch (PurchaseException $exception) {
            $this->assertSame('QUOTE_EXPIRED', $exception->errorCode);
        }
        $fresh = $service->refresh($f['buyer'], $card['id']);
        $result = $service->confirm($f['buyer'], $fresh['id'], true, $fresh['quote_id']);
        $this->assertSame('auto_confirmed', $result['path']);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_incomplete_preparation_rolls_back_and_can_be_corrected_within_same_turn(): void
    {
        $f = $this->fixture();
        $turn = $this->turn($f['buyer']);
        $args = [...$this->args($f), 'phone' => '123'];
        $invalid = app(AssistantTools::class)->call('prepare_order', $args, $f['buyer'], $turn);
        $this->assertSame('QUOTE_INCOMPLETE', $invalid['code']);
        $this->assertDatabaseCount('ai_checkouts', 0);
        $valid = app(AssistantTools::class)->call('prepare_order', $this->args($f), $f['buyer'], $turn);
        $this->assertTrue($valid['ok']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_installation_slot_fields_are_advertised_and_reach_the_shared_quote(): void
    {
        $schema = collect(AssistantTools::declarations())->firstWhere('name', 'prepare_order')['parameters']['properties'];
        $this->assertArrayHasKey('slot_start', $schema);
        $this->assertArrayHasKey('slot_end', $schema);

        $f = $this->verifiedFixture();
        Service::factory()->create(['glass_type' => 'windshield', 'price' => '5000.25', 'duration_minutes' => 60, 'is_default' => true]);
        $vehicle = $f['vehicle'];
        $vehicle['car_model_id'] = $vehicle['model_id'];
        unset($vehicle['model_id']);
        $args = [...$this->args($f), ...$vehicle, 'fulfillment' => 'installation'];
        $turn = $this->turn($f['buyer']);
        $tools = app(AssistantTools::class);

        $missing = $tools->call('prepare_order', $args, $f['buyer'], $turn);
        $this->assertTrue($missing['ok']);
        $this->assertSame('incomplete', $missing['checkout']['readiness']);
        $this->assertContains('SLOT_REQUIRED', $missing['checkout']['reasons']);
        $this->complete($turn);

        $prepared = app(AssistantCheckout::class)->updateDetails($f['buyer'], $missing['checkout']['id'], [
            'slot_start' => '2026-09-21T10:00:00+05:00',
            'slot_end' => '2026-09-21T11:30:00+05:00',
        ]);
        $this->assertSame('ready', $prepared['readiness']);
        $this->assertSame('2026-09-21T05:00:00+00:00', $prepared['fulfillment']['slot_start']);
        $this->assertSame('2026-09-21T06:30:00+00:00', $prepared['fulfillment']['slot_end']);
        $this->assertDatabaseCount('ai_checkouts', 1);
    }

    public function test_visit_with_explicit_slot_can_be_prepared_for_manager_review(): void
    {
        $f = $this->verifiedFixture();
        Service::factory()->create(['glass_type' => 'windshield', 'price' => '5000.25', 'duration_minutes' => 60, 'is_default' => true]);
        Service::factory()->create(['slug' => 'vyezd-mastera', 'glass_type' => null, 'price' => '2000.10', 'is_default' => false]);
        $vehicle = $f['vehicle'];
        $vehicle['car_model_id'] = $vehicle['model_id'];
        unset($vehicle['model_id']);

        $result = app(AssistantTools::class)->call('prepare_order', [
            ...$this->args($f), ...$vehicle,
            'fulfillment' => 'visit', 'address' => 'Тестовая, 12',
            'slot_start' => '2026-09-21T10:00:00+05:00',
            'slot_end' => '2026-09-21T11:30:00+05:00',
        ], $f['buyer'], $this->turn($f['buyer']));

        $this->assertTrue($result['ok']);
        $this->assertSame('review_required', $result['checkout']['readiness']);
        $this->assertContains('VISIT_RESOURCE_REVIEW_REQUIRED', $result['checkout']['reasons']);
        $this->assertNotContains('SLOT_REQUIRED', $result['checkout']['reasons']);
        $this->assertSame('2026-09-21T05:00:00+00:00', $result['checkout']['fulfillment']['slot_start']);
    }

    public function test_cancelled_generation_cannot_prepare_confirm_or_refresh_and_retry_reuses_checkout(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $service = app(AssistantCheckout::class);
        $turn = $this->turn($f['buyer']);
        $args = $this->args($f);
        $card = $service->prepare($f['buyer'], $args, $turn)['checkout'];
        app(ChatStore::class)->write($turn, null, 'cancelled');
        $this->assertSame([], $service->pendingForTurns($f['buyer'], [$turn]));
        foreach (['confirm', 'refresh'] as $action) {
            try {
                $action === 'refresh' ? $service->refresh($f['buyer'], $card['id']) : $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
                $this->fail('A cancelled answer cannot be submitted.');
            } catch (PurchaseException $exception) {
                $this->assertSame('TURN_NOT_COMPLETED', $exception->errorCode);
            }
        }
        $chat = Conversation::query()->where('owner_key', ChatIdentity::ownerKey($f['buyer']))->firstOrFail();
        $retry = app(ChatStore::class)->enqueue($chat, '', true);
        app(ChatStore::class)->claim($retry);
        $old = app(AssistantTools::class)->call('prepare_order', $args, $f['buyer'], $turn);
        $this->assertSame('TURN_NOT_ACTIVE', $old['code']);
        $retried = $service->prepare($f['buyer'], $args, $retry)['checkout'];
        $this->assertSame($card['id'], $retried['id']);
        $this->assertSame($card['draft_id'], $retried['draft_id']);
        $this->complete($retry);
        $visible = $service->pendingForTurns($f['buyer'], [$turn]);
        $this->assertCount(1, $visible);
        $service->confirm($f['buyer'], $card['id'], true, $card['quote_id']);
        $this->assertDatabaseCount('ai_checkouts', 1);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_changed_arguments_cannot_replace_prepared_checkout_and_forged_turn_cannot_create_it(): void
    {
        $f = $this->fixture();
        $turn = $this->turn($f['buyer']);
        $tools = app(AssistantTools::class);
        $first = $tools->call('prepare_order', $this->args($f), $f['buyer'], $turn);
        $changed = $tools->call('prepare_order', [...$this->args($f), 'quantity' => 2], $f['buyer'], $turn);
        $this->assertTrue($first['ok']);
        $this->assertSame('CHECKOUT_ALREADY_PREPARED', $changed['code']);
        $foreign = $tools->call('prepare_order', $this->args($f), BuyerContext::guest(str_repeat('b', 64)), $turn);
        $this->assertSame('RESOURCE_NOT_FOUND', $foreign['code']);
        $this->assertDatabaseCount('ai_checkouts', 1);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, DB::table('order_draft_items')->where('draft_id', $first['checkout']['draft_id'])->value('quantity'));
    }
}
