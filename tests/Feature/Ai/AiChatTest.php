<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\AssistantCheckout;
use App\Ai\AssistantDialog;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\Conversation;
use App\Ai\Runtime\GenerateReply;
use App\Domain\Identity\BuyerContext;
use App\Livewire\AiChat;
use App\Models\AiExchangeLog;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsQuoteFixtures;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use BuildsQuoteFixtures, RefreshDatabase;

    private function enable(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key'], 'ai.mode' => 'gemini_only', 'ai.queue_connection' => 'database', 'ai.queue_name' => 'ai']);
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        Queue::fake();
    }

    public function test_multiple_dialogues_restore_selection_and_delete_running_history(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'RAV4 1995')->call('send')->call('cancel');
        $first = $chat->get('conversationId');
        $chat->call('newConversation')->assertSet('messages.0.id', 'greeting')->set('draft', 'Camry 2018')->call('send');
        $second = $chat->get('conversationId');
        $turn = $this->latestTurn();
        $this->assertNotSame($first, $second);
        Livewire::test(AiChat::class, ['embedded' => true])->assertSet('conversationId', $second)->assertSee('Camry 2018');
        $chat->call('selectConversation', $first)->assertSee('RAV4 1995');
        $chat->call('deleteConversation', $second);
        $this->assertDatabaseMissing('ai_conversations', ['id' => $second]);
        $this->assertDatabaseMissing('ai_messages', ['conversation_id' => $second]);
        $this->assertFalse(app(ChatStore::class)->active($turn->id));
        $this->assertNull(app(ChatStore::class)->claim($turn->id));
        app(ChatStore::class)->write($turn->id, 'Поздний ответ', 'completed');
        $chat->call('deleteConversation', $first)->assertDontSee('RAV4 1995');
        $this->assertDatabaseCount('ai_conversations', 1);
    }

    public function test_guest_cannot_open_or_delete_another_owners_dialogue(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true]);
        $id = $chat->get('conversationId');
        session(['ordering.guest_token' => str_repeat('b', 64)]);
        Livewire::test(AiChat::class, ['embedded' => true])->call('selectConversation', $id)->assertNotFound();
        Livewire::test(AiChat::class, ['embedded' => true])->call('deleteConversation', $id)->assertNotFound();
        $this->assertDatabaseHas('ai_conversations', ['id' => $id]);
    }

    public function test_closed_widget_does_not_load_history_and_idle_chat_does_not_poll(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->assertSet('conversationId', null)->assertSet('messages', []);
        $this->assertDatabaseCount('ai_conversations', 0);
        Livewire::test(AiChat::class, ['embedded' => true])->assertDontSee('wire:poll', false)
            ->set('draft', 'Camry')->call('send')->assertSee('wire:poll.1s.visible', false);
    }

    private function latestTurn(): object
    {
        return DB::table('ai_turns')->orderByDesc('model_message_id')->orderByDesc('attempt')->first();
    }

    private function reply(string $text = 'Какая машина?'): void
    {
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function (array $messages, BuyerContext $buyer, callable $chunk, callable $cancelled, string $operation) use ($text) {
            $this->assertFalse($cancelled());
            $chunk($text);

            return $text;
        });
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
    }

    public function test_disabled_chat_is_absent(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('Чат с помощником');
    }

    public function test_enabled_chat_page_preserves_embedded_layout_and_enter_handling(): void
    {
        $this->enable();
        $html = $this->get(route('assistant'))->assertOk()->assertSee('Camry 2018, лобовое')->assertSee('Помогу подобрать стекло')->getContent();
        $this->assertStringContainsString('data-ai-chat="embedded"', $html);
        $this->assertStringContainsString('wire:click="send"', $html);
        $this->assertStringContainsString('x-on:keydown.enter=', $html);
        $this->assertStringNotContainsString('wire:submit="send"', $html);
    }

    public function test_chat_without_provider_is_closed(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => [], 'ai.mode' => 'gemini_only']);
        $this->get(route('assistant'))->assertNotFound();
    }

    public function test_send_is_short_and_queues_only_one_generation(): void
    {
        $this->enable();
        Http::fake();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry 2018')->call('send')
            ->assertSet('activeStatus', 'queued')->assertSee('Camry 2018');
        $chat->set('draft', 'повторный клик')->call('send');
        Queue::assertPushed(GenerateReply::class, 1);
        Queue::assertPushed(GenerateReply::class, fn ($job) => $job->connection === 'database' && $job->queue === 'ai');
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_turns', 1);
        $this->assertDatabaseCount('ai_messages', 2);
        $this->reply();
        $chat->call('refreshChat')->assertSee('Какая машина?')->assertSet('activeStatus', null);
    }

    public function test_reconnect_restores_persisted_history_and_pending_generation(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'Camry 2018')->call('send');
        Livewire::test(AiChat::class, ['embedded' => true])->assertSee('Camry 2018')->assertSet('activeStatus', 'queued');
        $this->reply();
        Livewire::test(AiChat::class, ['embedded' => true])->assertSee('Какая машина?');
        Queue::assertPushed(GenerateReply::class, 1);
    }

    public function test_completed_tool_offer_order_survives_reconnect_without_copying_checkout_contacts(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'Покажи стекла')->call('send');
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function ($messages, $buyer, $chunk, $cancelled, $turnId, $onToolResult) {
            $onToolResult('search_products', ['query' => 'Camry'], ['items' => [
                ['offer_id' => 91, 'product_id' => 10, 'name' => 'Первое Camry', 'unit_price' => ['amount_minor' => 3335, 'currency' => 'KZT'], 'fitment' => ['status' => 'matched']],
                ['offer_id' => 92, 'product_id' => 10, 'name' => 'Второе Camry', 'unit_price' => ['amount_minor' => 4500, 'currency' => 'KZT'], 'fitment' => ['status' => 'review_required']],
            ], 'meta' => ['total' => 2]]);
            $onToolResult('prepare_order', ['name' => 'Секретное имя', 'phone' => '77001234567'], ['ok' => true, 'checkout' => ['id' => 'card-id', 'status' => 'pending'], 'note' => 'Секретное имя']);

            return 'Выберите предложение.';
        });
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
        Livewire::test(AiChat::class)->set('draft', 'Первое предложение')->call('send');
        $history = app(ChatStore::class)->history($this->latestTurn());
        $tools = collect($history)->firstWhere('role', 'tool_context')['tools'];
        $this->assertSame([91, 92], array_column($tools[0]['result']['items'], 'offer_id'));
        $this->assertSame(3335, $tools[0]['result']['items'][0]['unit_price']['amount_minor']);
        $this->assertSame('Camry', $tools[0]['args']['query']);
        $this->assertStringNotContainsString('Секретное имя', json_encode($tools, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('77001234567', json_encode($tools, JSON_UNESCAPED_UNICODE));
        Livewire::test(AiChat::class, ['embedded' => true])
            ->assertSee('Первое')
            ->assertSee('Выбрать')
            ->call('chooseOffer', 91)
            ->assertSet('draft', 'Беру offer_id 91: Первое Camry');
    }

    public function test_finished_turn_logs_the_question_and_drops_other_cars_from_the_cards(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'vw t4 лобовое 1998, телефон 77001234567')->call('send');
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function ($messages, $buyer, $chunk, $cancelled, $turnId, $onToolResult) {
            $onToolResult('search_products', ['query' => 'vw t4 лобовое 1998'], ['items' => [
                ['offer_id' => 91, 'product_id' => 10, 'name' => 'Лобовое Volkswagen TRANSP T4', 'unit_price' => ['amount_minor' => 4350000, 'currency' => 'KZT'], 'fitment' => ['status' => 'review_required']],
                ['offer_id' => 92, 'product_id' => 11, 'name' => 'Лобовое BMW 3 E46 1998', 'unit_price' => ['amount_minor' => 2350000, 'currency' => 'KZT'], 'fitment' => ['status' => 'review_required']],
            ], 'meta' => ['total' => 2]]);

            return 'Вот лобовое T4.';
        });
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));

        $log = AiExchangeLog::query()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('t4', mb_strtolower($log->question));
        $this->assertStringNotContainsString('77001234567', $log->question);
        $this->assertSame('Вот лобовое T4.', $log->answer);
        $this->assertCount(2, $log->raw_offers);
        $this->assertSame(['Лобовое Volkswagen TRANSP T4'], $log->shown_offers);
        Livewire::test(AiChat::class, ['embedded' => true])
            ->assertSee('TRANSP T4')
            ->assertDontSee('BMW');
    }

    public function test_failed_attempt_tool_facts_are_removed_before_retry_and_stale_callback_is_ignored(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class)->set('draft', 'Camry')->call('send');
        $old = $this->latestTurn();
        $store = app(ChatStore::class);
        $store->claim($old->id);
        $store->recordToolResult($old->id, 'search_products', [], ['items' => [['offer_id' => 91]]]);
        $this->assertDatabaseCount('ai_tool_results', 1);
        $chat->call('cancel')->call('retry');
        $this->assertDatabaseCount('ai_tool_results', 0);
        $store->recordToolResult($old->id, 'search_products', [], ['items' => [['offer_id' => 92]]]);
        $this->assertDatabaseCount('ai_tool_results', 0);
    }

    public function test_actual_chunk_is_persisted_before_generation_returns(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Привет')->call('send');
        $turn = $this->latestTurn();
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function ($history, $buyer, $chunk) use ($turn, $chat) {
            $chunk('Настоящий фрагмент');
            $this->assertSame('Настоящий фрагмент', \App\Ai\Runtime\MessageText::decode(DB::table('ai_messages')->where('id', $turn->model_message_id)->value('text')));
            $chat->call('refreshChat')->assertSee('Настоящий фрагмент')->assertSet('activeStatus', 'running');
            $chunk(' ответа');

            return 'Настоящий фрагмент ответа';
        });
        (new GenerateReply($turn->id))->handle($dialog, app(ChatStore::class));
        $chat->call('refreshChat')->assertSee('Настоящий фрагмент ответа');
    }

    public function test_cancel_and_retry_preserve_one_user_message_and_reject_old_worker(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry')->call('send');
        $old = $this->latestTurn();
        $store = app(ChatStore::class);
        $store->claim($old->id);
        $store->write($old->id, 'Частичный ответ');
        $chat->call('cancel')->assertSet('canRetry', true)->call('retry')->assertSet('activeStatus', 'queued')->assertSet('canRetry', false);
        $new = $this->latestTurn();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($old->operation_id, $new->operation_id);
        $this->assertSame(2, $new->attempt);
        $this->assertSame(1, DB::table('ai_messages')->where('role', 'user')->count());
        $store->write($old->id, 'Запоздавший ответ', 'completed');
        $this->assertNull($store->claim($old->id));
        $this->assertSame('', DB::table('ai_messages')->where('id', $new->model_message_id)->value('text'));
        $this->reply('Новый ответ');
        $chat->call('refreshChat')->assertSee('Новый ответ')->assertDontSee('Запоздавший ответ');
    }

    public function test_cancellation_during_stream_does_not_publish_final_text(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'Camry')->call('send');
        $turn = $this->latestTurn();
        $store = app(ChatStore::class);
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function ($history, $buyer, $chunk, $cancelled) use ($turn, $store) {
            $chunk('Начало');
            $store->write($turn->id, null, 'cancelled');
            $this->assertTrue($cancelled());
            $chunk(' проигнорировать');

            return 'Нельзя публиковать';
        });
        (new GenerateReply($turn->id))->handle($dialog, $store);
        $this->assertSame('cancelled', DB::table('ai_turns')->where('id', $turn->id)->value('status'));
        $this->assertSame('Начало', \App\Ai\Runtime\MessageText::decode(DB::table('ai_messages')->where('id', $turn->model_message_id)->value('text')));
    }

    public function test_worker_not_started_and_dead_worker_recover_to_retryable_state(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry')->call('send');
        $first = $this->latestTurn();
        DB::table('ai_turns')->where('id', $first->id)->update(['deadline_at' => now()->subSecond()]);
        $chat->call('refreshChat')->assertSet('activeStatus', null)->assertSet('canRetry', true)->assertSee('на паузе');
        $this->assertSame('worker_unavailable', DB::table('ai_turns')->where('id', $first->id)->value('error_code'));
        $this->assertNull(app(ChatStore::class)->claim($first->id));
        $chat->call('retry');
        $second = $this->latestTurn();
        app(ChatStore::class)->claim($second->id);
        DB::table('ai_turns')->where('id', $second->id)->update(['deadline_at' => now()->subSecond()]);
        $chat->call('refreshChat')->assertSet('canRetry', true);
        $this->assertSame('generation_timeout', DB::table('ai_turns')->where('id', $second->id)->value('error_code'));
    }

    public function test_failure_is_safe_and_retry_does_not_duplicate_user_text(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry')->call('send');
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andThrow(new \RuntimeException('secret api_key=123'));
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
        $chat->call('refreshChat')->assertSee('Не получилось ответить')->assertDontSee('api_key')->call('retry');
        $this->assertSame(1, DB::table('ai_messages')->where('role', 'user')->count());
    }

    public function test_rate_limit_also_applies_to_retry(): void
    {
        $this->enable();
        config(['ai.rate_limit_per_minute' => 1]);
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry')->call('send')->call('cancel');
        $chat->call('retry')->assertSee('подождите минуту');
        Queue::assertPushed(GenerateReply::class, 1);
    }

    public function test_sync_queue_configuration_never_runs_network_in_web_request(): void
    {
        $this->enable();
        config(['ai.queue_connection' => 'sync']);
        Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Camry')->call('send')->assertSet('canRetry', true);
        $this->assertSame('queue_unavailable', $this->latestTurn()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_guest_identity_is_isolated_and_token_is_encrypted_at_rest(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'Частный подбор')->call('send');
        $this->assertNotSame(str_repeat('a', 64), DB::table('ai_conversations')->value('guest_token'));
        session(['ordering.guest_token' => str_repeat('b', 64)]);
        Livewire::test(AiChat::class, ['embedded' => true])->assertDontSee('Частный подбор')->assertSet('activeStatus', null);
    }

    public function test_switching_account_clears_client_history_before_any_action(): void
    {
        $this->enable();
        $one = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $two = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $chat = Livewire::actingAs($one)->test(AiChat::class, ['embedded' => true])->set('draft', 'Секрет первого')->call('send');
        $oldConversation = $chat->get('conversationId');
        $this->actingAs($two);
        $chat->call('refreshChat')->assertDontSee('Секрет первого')->assertSet('activeStatus', null);
        $this->assertNotSame($oldConversation, $chat->get('conversationId'));
        $chat->call('cancel');
        $this->assertNotNull(Conversation::findOrFail($oldConversation)->active_turn_id);
    }

    public function test_account_without_customer_gets_safe_notice_instead_of_server_error(): void
    {
        $this->enable();
        Livewire::actingAs(User::factory()->create())->test(AiChat::class, ['embedded' => true])
            ->assertSet('identityUnavailable', true)->assertSee('Чат доступен покупателям и гостям');
    }

    public function test_job_refreshes_account_and_uses_explicit_locale(): void
    {
        $this->enable();
        $user = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->actingAs($user);
        app()->setLocale('kk');
        Livewire::test(AiChat::class)->set('draft', 'Camry')->call('send');
        app()->setLocale('ru');
        auth()->logout();
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldReceive('reply')->once()->andReturnUsing(function ($history, BuyerContext $buyer) use ($user) {
            $this->assertSame($user->id, $buyer->actorId);
            $this->assertSame('kk', app()->getLocale());

            return 'Жауап';
        });
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
        $this->assertSame('ru', app()->getLocale());
    }

    public function test_revoked_account_is_not_sent_to_model_by_queued_job(): void
    {
        $this->enable();
        $user = User::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        Livewire::actingAs($user)->test(AiChat::class)->set('draft', 'Camry')->call('send');
        $user->update(['is_active' => false]);
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldNotReceive('reply');
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
        $this->assertSame('failed', $this->latestTurn()->status);
    }

    public function test_replayed_job_is_claimed_only_once(): void
    {
        $this->enable();
        Livewire::test(AiChat::class)->set('draft', 'Camry')->call('send');
        $this->reply();
        $dialog = $this->mock(AssistantDialog::class);
        $dialog->shouldNotReceive('reply');
        (new GenerateReply($this->latestTurn()->id))->handle($dialog, app(ChatStore::class));
        $this->assertSame('completed', $this->latestTurn()->status);
    }

    private function preparedChat(): array
    {
        $this->enable();
        $fixture = $this->fixture();
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Подготовь заявку')->call('send');
        $turn = $this->latestTurn();
        $store = app(ChatStore::class);
        $store->claim($turn->id);
        $card = app(AssistantCheckout::class)->prepare($fixture['buyer'], [
            'name' => 'Клиент', 'phone' => '77001234567', 'offer_id' => $fixture['offer']->id,
            'fulfillment' => 'pickup', 'city' => $fixture['point']->city, 'service_point_id' => $fixture['point']->id,
        ], $turn->id)['checkout'];
        $store->write($turn->id, 'Проверьте подготовленную заявку.', 'completed');
        $chat->call('refreshChat');

        return [$chat, $card, $fixture];
    }

    public function test_checkout_requires_explicit_consent_and_restores_submitted_result(): void
    {
        [$chat, $card] = $this->preparedChat();
        $chat->assertSee('Самовывоз')->assertSee('Караганда')->call('confirmCheckout', $card['id']);
        $this->assertDatabaseCount('orders', 0);
        $chat->set('privacyConsent.'.$card['id'], true)->call('confirmCheckout', $card['id'])
            ->assertSee('Заявка принята')
            ->assertSee('Найти заявку по номеру');
        $this->assertDatabaseCount('orders', 1);
        $chat->set('privacyConsent.'.$card['id'], true)->call('confirmCheckout', $card['id']);
        $this->assertDatabaseCount('orders', 1);
        Livewire::test(AiChat::class, ['embedded' => true])->assertSee('Заявка принята');
    }

    public function test_checkout_card_shows_vehicle_and_manager_reason(): void
    {
        $this->enable();
        $fixture = $this->fixture();
        $brand = CarBrand::factory()->create(['name' => 'Toyota']);
        $model = CarModel::factory()->create(['car_brand_id' => $brand->id, 'name' => 'Camry']);
        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Подготовь заявку')->call('send');
        $turn = $this->latestTurn();
        $store = app(ChatStore::class);
        $store->claim($turn->id);
        app(AssistantCheckout::class)->prepare($fixture['buyer'], [
            'name' => 'Клиент', 'phone' => '77001234567', 'offer_id' => $fixture['offer']->id,
            'fulfillment' => 'pickup', 'city' => $fixture['point']->city, 'service_point_id' => $fixture['point']->id,
            'car_model_id' => $model->id, 'year' => 2018,
        ], $turn->id);
        $store->write($turn->id, 'Проверьте подготовленную заявку.', 'completed');
        $chat->call('refreshChat')
            ->assertSee('Toyota Camry')
            ->assertSee('2018');
    }

    public function test_confirmed_order_number_is_available_in_followup_model_context(): void
    {
        [$chat, $card] = $this->preparedChat();
        $chat->set('privacyConsent.'.$card['id'], true)->call('confirmCheckout', $card['id']);
        $number = DB::table('orders')->value('number');
        $chat->set('draft', 'Напомни номер моей заявки')->call('send');
        $history = app(ChatStore::class)->history($this->latestTurn());
        $tools = collect($history)->firstWhere('role', 'tool_context')['tools'];
        $this->assertSame($number, collect($tools)->last()['result']['order_number']);
    }

    public function test_money_keeps_nonzero_minor_units(): void
    {
        $this->enable();
        $chat = Livewire::test(AiChat::class)->instance();
        $this->assertSame('33,35 ₸', $chat->money(['amount_minor' => 3335, 'currency' => 'KZT']));
        $this->assertSame('45 ₸', $chat->money(['amount_minor' => 4500, 'currency' => 'KZT']));
    }

    public function test_changed_quote_clears_consent_and_requires_new_confirmation(): void
    {
        [$chat, $card, $fixture] = $this->preparedChat();
        $chat->set('privacyConsent.'.$card['id'], true);
        app(AssistantCheckout::class)->refresh($fixture['buyer'], $card['id']);
        $chat->call('confirmCheckout', $card['id']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse($chat->get('privacyConsent')[$card['id']] ?? false);
        $chat->set('privacyConsent.'.$card['id'], true)->call('confirmCheckout', $card['id']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_another_guest_cannot_confirm_or_refresh_known_checkout(): void
    {
        [, $card] = $this->preparedChat();
        session(['ordering.guest_token' => str_repeat('b', 64)]);
        Livewire::test(AiChat::class, ['embedded' => true])->set('privacyConsent.'.$card['id'], true)
            ->call('confirmCheckout', $card['id'])->call('refreshCheckout', $card['id'])->assertSet('checkouts', []);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_fast_prepare_uses_authenticated_customer_profile_and_allows_inline_checkout_editing(): void
    {
        $this->enable();
        $fixture = $this->fixture();
        $customer = Customer::factory()->create([
            'name' => 'Постоянный Клиент',
            'phone' => '77019998877',
            'default_city' => $fixture['point']->city,
            'preferred_fulfillment' => 'pickup',
        ]);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($user);

        $chat = Livewire::test(AiChat::class, ['embedded' => true])->set('draft', 'Покажи стекла')->call('send');
        $turn = $this->latestTurn();
        $store = app(ChatStore::class);
        $store->claim($turn->id);
        $store->recordToolResult($turn->id, 'search_products', ['query' => 'Camry'], [
            'items' => [[
                'offer_id' => $fixture['offer']->id,
                'product_id' => $fixture['offer']->product_id,
                'name' => 'Лобовое Camry 70',
                'article' => $fixture['offer']->article,
                'unit_price' => ['amount_minor' => 3500000, 'currency' => 'KZT'],
                'available_qty' => 5,
                'fitment' => ['status' => 'matched'],
            ]],
            'meta' => ['total' => 1],
        ]);
        $store->write($turn->id, 'Выберите карточку.', 'completed');

        $chat->call('refreshChat')
            ->assertSee('Лобовое Camry 70')
            ->call('prepareOffer', $fixture['offer']->id)
            ->assertSee('Проверьте заявку')
            ->assertSee('Постоянный Клиент');

        $cardId = $chat->get('checkouts')[0]['id'];
        $chat->set('checkoutEdits.'.$cardId.'.name', 'Обновлённое Имя')
            ->set('checkoutEdits.'.$cardId.'.fulfillment', 'delivery')
            ->set('checkoutEdits.'.$cardId.'.address', 'Абая 10')
            ->call('updateCheckoutDetails', $cardId)
            ->assertSee('Обновлённое Имя')
            ->assertSee('Абая 10');
    }
}
