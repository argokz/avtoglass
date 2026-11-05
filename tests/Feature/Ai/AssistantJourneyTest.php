<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Actions\AcceptCashPayment;
use App\Actions\ApplyPaymentCallback;
use App\Actions\StartPayment;
use App\Ai\AssistantCheckout;
use App\Ai\AssistantDialog;
use App\Ai\AssistantTools;
use App\Ai\JourneyTools;
use App\Ai\Providers\ChatProvider;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\MessageText;
use App\Cart\Cart;
use App\Domain\Assistant\MemoryService;
use App\Domain\Assistant\PersonalDataCleanup;
use App\Domain\Assistant\ProcurementService;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\InvoicePayments;
use App\Domain\Ordering\OrderProposalService;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Ordering\SalesMode;
use App\Domain\Pricing\Models\CheckoutQuote;
use App\Domain\Shared\PurchaseException;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Livewire\AssistantMemory;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use App\Models\User;
use App\Payments\CallbackResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Support\BuildsQuoteFixtures;
use Tests\TestCase;

final class AssistantJourneyTest extends TestCase
{
    use BuildsQuoteFixtures, RefreshDatabase;

    private function account(): BuyerContext
    {
        return BuyerContext::account(User::factory()->create(['customer_id' => Customer::factory()->create()->id])->id);
    }

    public function test_memory_requires_consent_detects_stale_edits_and_is_isolated(): void
    {
        $buyer = $this->account();
        $memory = app(MemoryService::class);
        try {
            $memory->save($buyer, ['city' => 'Караганда'], 1);
            $this->fail('Consent required');
        } catch (PurchaseException $e) {
            $this->assertSame('MEMORY_CONSENT_REQUIRED', $e->errorCode);
        }
        $version = $memory->consent($buyer, true)->version;
        $memory->save($buyer, ['city' => 'Караганда', 'address' => 'Тестовая 1'], $version);
        $this->assertSame([], $memory->values($this->account()));
        $this->assertStringNotContainsString('Тестовая', DB::table('assistant_memories')->value('facts'));
        try {
            $memory->save($buyer, ['city' => 'Алматы'], $version);
            $this->fail('Stale edit');
        } catch (PurchaseException $e) {
            $this->assertSame('MEMORY_VERSION_CONFLICT', $e->errorCode);
        }
        $memory->consent($buyer, false);
        $this->assertSame([], $memory->values($buyer));
    }

    public function test_vehicle_change_drops_options_and_messages_are_encrypted(): void
    {
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $buyer = BuyerContext::guest(str_repeat('a', 64));
        $model = CarModel::factory()->create(['name' => 'Camry']);
        $other = CarModel::factory()->create(['name' => 'RAV4']);
        $store = app(ChatStore::class);
        $chat = $store->create($buyer);
        $turn = $store->enqueue($chat, 'Лобовое Camry 2018 с камерой');
        $store->write($turn, 'Найдено', 'completed');
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertSame($model->id, $context['intent']['car_model_id']);
        $this->assertTrue($context['intent']['options']['camera']);
        $raw = DB::table('ai_messages')->where('role', 'user')->value('text');
        $this->assertStringStartsWith('enc:v1:', $raw);
        $this->assertStringContainsString('Camry', MessageText::decode($raw));
        $store->enqueue($chat, 'RAV4 1995 лобовое');
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertSame($other->id, $context['intent']['car_model_id']);
        $this->assertSame(1995, $context['intent']['year']);
        $this->assertArrayNotHasKey('options', $context['intent']);
    }

    public function test_explicit_code_uses_catalog_without_a_model_round_trip(): void
    {
        $offer = Offer::factory()->create(['article' => 'FAST-123']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldNotReceive('generate');
        $result = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([['role' => 'user', 'text' => 'Найди FAST-123']], null, null, null, null,
            function ($name, $args, $data) use (&$result) {
                $result = $data;
            }, ['intent' => []]);
        $this->assertSame($offer->id, $result['items'][0]['offer_id']);
    }

    public function test_specification_keeps_alternatives_and_missing_lines(): void
    {
        $buyer = $this->account();
        $chat = app(ChatStore::class)->create($buyer);
        $first = Offer::factory()->create(['article' => 'LIST-100']);
        Offer::factory()->create(['article' => 'LIST-100']);
        $service = app(ProcurementService::class);
        $lines = $service->replace($buyer, $chat->id, [['article' => 'LIST-100', 'quantity' => 2], ['article' => 'ABSENT-404', 'quantity' => 1]]);
        $this->assertSame('ambiguous', $lines[0]['status']);
        $this->assertSame('unresolved', $lines[1]['status']);
        $service->choose($buyer, $chat->id, $lines[0]['id'], $first->id);
        $items = $service->items(app(ShoppingService::class)->get($chat));
        $this->assertCount(2, $items);
        $this->assertSame($first->id, $items[0]['offer_id']);
        $this->assertNull($items[1]['offer_id']);
        $this->assertTrue($items[0]['article_confirmed']);
    }

    public function test_approved_wholesale_confirms_through_its_own_action(): void
    {
        Queue::fake();
        config(['ordering.wholesale_auto_checkout_enabled' => true]);
        $f = $this->fixture(true);
        $quote = $this->prepare($f);
        $this->assertSame('ready', $quote['readiness']);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'wholesale-new', $quote['id'], $f['draft']->version, 'confirm_wholesale');
        $this->assertTrue($result->data['reserved']);
        $this->assertSame('wholesale', Order::query()->sole()->purchase_snapshot['sales_mode']);
        $this->assertDatabaseCount('stock_reservations', 1);
    }

    public function test_review_order_cannot_be_paid_with_a_known_total(): void
    {
        Queue::fake();
        settings()->save(['payments' => true]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'review-payment', $quote['id'], $f['draft']->version, 'submit_for_review');
        $order = Order::query()->findOrFail($result->data['order_id']);
        $this->assertFalse(app(StartPayment::class)->isPayable($order));
        $this->assertSame($order->id, app(CustomerPurchases::class)->owned($f['buyer'], $order->public_token)->id);
    }

    public function test_late_success_is_saved_without_promising_unavailable_stock(): void
    {
        Queue::fake();
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'late-payment', $quote['id'], $f['draft']->version, 'confirm_retail');
        $order = Order::query()->findOrFail($result->data['order_id']);
        StockReservation::query()->update(['expires_at' => now()->subMinute()]);
        $f['offer']->update(['stock_qty' => 0]);
        $payment = Payment::query()->create(['order_id' => $order->id, 'provider' => 'test', 'amount' => $order->total_amount, 'currency' => 'KZT', 'status' => PaymentStatus::Pending]);
        $callback = new CallbackResult($payment->token, PaymentStatus::Succeeded, (float) $order->total_amount);
        $this->assertTrue(app(ApplyPaymentCallback::class)->handle($payment, $callback));
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame('paid_review', $order->refresh()->purchase_state);
        $this->assertFalse(app(ApplyPaymentCallback::class)->handle($payment, $callback));
        $this->assertSame(1, $order->tasks()->count());
    }

    public function test_manager_proposal_updates_one_order_and_reserves_once(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'proposal-review', $quote['id'], $f['draft']->version, 'submit_for_review');
        $order = Order::query()->findOrFail($result->data['order_id']);
        $staff = User::factory()->create(['role' => UserRole::Manager]);
        $proposal = app(OrderProposalService::class)->propose($order, $staff);
        app(OrderProposalService::class)->accept($f['buyer'], $order->public_token, $proposal->id);
        app(OrderProposalService::class)->accept($f['buyer'], $order->public_token, $proposal->id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame('confirmed', $order->refresh()->purchase_state);
        $this->assertSame($order->id, app(CustomerPurchases::class)->owned($f['buyer'], $order->public_token)->id);
    }

    public function test_cash_payment_keeps_full_reserve_and_cannot_be_applied_twice(): void
    {
        Queue::fake();
        config(['ordering.auto_checkout_enabled' => true]);
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'cash-test', $quote['id'], $f['draft']->version, 'confirm_retail');
        $order = Order::query()->findOrFail($result->data['order_id']);
        $staff = User::factory()->create(['role' => UserRole::Manager]);
        $cash = app(AcceptCashPayment::class);
        $this->assertNull($cash->handle($order, 1, $staff));
        $this->assertNotNull($cash->handle($order, (float) $order->total_amount, $staff));
        $this->assertSame('paid', $order->refresh()->purchase_state);
        $this->assertNull($cash->handle($order, (float) $order->total_amount, $staff));
        $this->assertNull(StockReservation::query()->sole()->expires_at);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_invoice_reference_is_idempotent_and_cannot_pay_another_order(): void
    {
        Queue::fake();
        config(['ordering.wholesale_auto_checkout_enabled' => true]);
        settings()->save(['legal_name' => 'Тестовая организация', 'iik' => 'KZ000000000000000000']);
        $orders = [];
        for ($i = 0; $i < 2; $i++) {
            $f = $this->fixture(true);
            $f['draft'] = app(DraftService::class)->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['payment_method' => 'invoice']);
            $quote = $this->prepare($f);
            $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'invoice-test-'.$i, $quote['id'], $f['draft']->version, 'confirm_wholesale');
            $orders[] = Order::query()->findOrFail($result->data['order_id']);
        }
        $staff = User::factory()->create(['role' => UserRole::Manager]);
        $invoices = app(InvoicePayments::class);
        $payment = $invoices->confirm($orders[0], $staff, 'BANK-001');
        $this->assertSame($payment->id, $invoices->confirm($orders[0], $staff, ' bank-001 ')->id);
        $this->assertSame('paid', $orders[0]->refresh()->purchase_state);
        try {
            $invoices->confirm($orders[1], $staff, 'BANK-001');
            $this->fail('One transfer must not pay two orders');
        } catch (PurchaseException $exception) {
            $this->assertSame('PAYMENT_REFERENCE_ALREADY_USED', $exception->errorCode);
        }
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_importing_guest_purchase_grants_only_that_order_and_retains_payment(): void
    {
        Queue::fake();
        config(['ordering.auto_checkout_enabled' => true]);
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $f = $this->fixture();
        $store = app(ChatStore::class);
        $chat = $store->create($f['buyer']);
        $turn = $store->enqueue($chat, 'Список для покупки');
        $store->claim($turn);
        $prepared = app(AssistantCheckout::class)->prepare($f['buyer'], ['name' => 'Гость', 'phone' => '+77001234567', 'city' => $f['point']->city, 'service_point_id' => $f['point']->id], $turn, [$f['item']])['checkout'];
        $store->write($turn, 'Готово', 'completed');
        $submitted = app(AssistantCheckout::class)->confirm($f['buyer'], $prepared['id'], true, $prepared['quote_id']);
        $order = Order::query()->where('public_token', $submitted['id'])->firstOrFail();
        $originalCustomer = $order->customer_id;
        $account = $this->account();
        app(ShoppingService::class)->claimGuest($account, str_repeat('a', 64), $chat->id);
        $this->assertSame($order->id, app(CustomerPurchases::class)->owned($account, $order->public_token)->id);
        $this->assertSame($originalCustomer, $order->refresh()->customer_id);
        $this->assertSame('confirmed', $order->purchase_state);
        $this->assertDatabaseCount('purchase_access_grants', 1);
        $other = $this->account();
        $this->expectException(PurchaseException::class);
        app(CustomerPurchases::class)->owned($other, $order->public_token);
    }

    public function test_retention_deletes_history_but_keeps_opt_in_memory(): void
    {
        $buyer = $this->account();
        app(MemoryService::class)->consent($buyer, true);
        $store = app(ChatStore::class);
        $chat = $store->create($buyer);
        $turn = $store->enqueue($chat, 'Караганда, тестовый адрес');
        $store->write($turn, 'Сохранено', 'completed');
        DB::table('ai_conversations')->where('id', $chat->id)->update(['updated_at' => now()->subDays(181)]);
        $this->artisan('assistant:prune')->assertSuccessful();
        $this->assertDatabaseCount('ai_conversations', 0);
        $this->assertDatabaseCount('ai_messages', 0);
        $this->assertDatabaseCount('assistant_memories', 1);
    }

    public function test_model_assumptions_do_not_become_confirmed_vehicle_facts(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([['functionCall' => ['name' => 'search_products', 'args' => ['query' => 'Camry', 'year' => 2018, 'options' => ['camera' => true]]]]]);
        $seen = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([['role' => 'user', 'text' => 'Помоги выбрать стекло']], $this->account(), null, null, null,
            function ($name, $args) use (&$seen) {
                $seen = $args;
            }, ['intent' => []]);
        $this->assertArrayNotHasKey('year', $seen);
        $this->assertArrayNotHasKey('options', $seen);
    }

    public function test_negative_model_quantity_cannot_reduce_a_draft(): void
    {
        $f = $this->fixture();
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $store = app(ChatStore::class);
        $chat = $store->create($f['buyer']);
        app(ShoppingService::class)->get($chat)->forceFill(['draft_id' => $f['draft']->id])->save();
        $turn = $store->enqueue($chat, 'Добавь 2');
        $store->claim($turn);
        try {
            app(JourneyTools::class)->call('update_draft', ['quantity' => -2, 'operation' => 'add'], $f['buyer'], $turn);
            $this->fail('Negative quantity');
        } catch (PurchaseException $exception) {
            $this->assertSame('INVALID_DRAFT_CHANGE', $exception->errorCode);
        }
        $this->assertSame(3, app(DraftService::class)->get($f['buyer'], $f['draft']->id)->jsonSerialize()['items'][0]['quantity']);
    }

    public function test_anonymization_cleans_quote_and_order_without_losing_financial_terms(): void
    {
        Queue::fake();
        $f = $this->fixture();
        $quote = $this->prepare($f);
        $result = app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'cleanup-test', $quote['id'], $f['draft']->version, 'submit_for_review');
        $order = Order::query()->findOrFail($result->data['order_id']);
        $amount = $order->total_amount;
        DB::transaction(fn () => app(PersonalDataCleanup::class)->customer($order->customer));
        $this->assertArrayNotHasKey('contacts', $order->refresh()->purchase_snapshot);
        $this->assertArrayNotHasKey('contacts', CheckoutQuote::query()->findOrFail($quote['id'])->snapshot);
        $this->assertSame($amount, $order->total_amount);
        $this->assertNull($order->purchase_guest_owner_hash);
    }

    public function test_vehicle_correction_invalidates_draft_selection_and_old_quote(): void
    {
        Queue::fake();
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $f = $this->fixture();
        $first = CarModel::factory()->create(['name' => 'Camry']);
        $second = CarModel::factory()->create(['name' => 'RAV4']);
        $drafts = app(DraftService::class);
        $f['draft'] = $drafts->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['vehicle' => ['model_id' => $first->id, 'year' => 2018, 'options' => ['camera' => true]]]);
        $quote = $this->prepare($f);
        $chat = app(ChatStore::class)->create($f['buyer']);
        app(ShoppingService::class)->get($chat)->forceFill(['draft_id' => $f['draft']->id, 'state' => ['intent' => ['car_model_id' => $first->id, 'year' => 2018, 'query' => 'Camry', 'options' => ['camera' => true]]]])->save();
        app(ChatStore::class)->enqueue($chat, 'Теперь RAV4 1995 лобовое');
        $current = $drafts->get($f['buyer'], $f['draft']->id)->jsonSerialize();
        $this->assertSame([], $current['items']);
        $this->assertSame($second->id, $current['vehicle']['model_id']);
        $this->assertArrayNotHasKey('options', $current['vehicle']);
        try {
            app(PurchaseCommands::class)->submitDraft($f['buyer'], $f['draft']->id, 'stale-car', $quote['id'], $f['draft']->version, 'submit_for_review');
            $this->fail('Stale selection');
        } catch (PurchaseException) {
            $this->assertDatabaseCount('orders', 0);
        }
    }

    public function test_new_conversation_does_not_merge_another_shopping_goal(): void
    {
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $f = $this->fixture();
        $other = Offer::factory()->create(['article' => 'OTHER-GOAL']);
        $store = app(ChatStore::class);
        $snapshots = [];
        foreach ([$f['offer'], $other] as $offer) {
            $chat = $store->create($f['buyer']);
            $turn = $store->enqueue($chat, 'Новый подбор');
            $store->claim($turn);
            $snapshots[] = app(AssistantCheckout::class)->prepare($f['buyer'], ['name' => 'Тест', 'phone' => '+77001234567', 'city' => $f['point']->city, 'service_point_id' => $f['point']->id], $turn, [
                ['offer_id' => $offer->id, 'quantity' => 1, 'selection_mode' => 'exact_article', 'article_confirmed' => true, 'manufacturer_confirmed' => true],
            ])['checkout'];
            $store->write($turn, 'Готово', 'completed');
        }
        $this->assertNotSame($snapshots[0]['draft_id'], $snapshots[1]['draft_id']);
        $this->assertCount(1, $snapshots[1]['items']);
        $this->assertSame($other->id, $snapshots[1]['items'][0]['offer_id']);
    }

    public function test_single_saved_car_does_not_override_an_explicit_unknown_model(): void
    {
        $buyer = $this->account();
        $memory = app(MemoryService::class);
        $version = $memory->consent($buyer, true)->version;
        $model = CarModel::factory()->create(['name' => 'Camry']);
        $memory->save($buyer, ['vehicles' => [['id' => (string) Str::uuid(), 'label' => 'Camry', 'car_model_id' => $model->id, 'year' => 2018]]], $version);
        $chat = app(ChatStore::class)->create($buyer);
        app(ShoppingService::class)->get($chat)->forceFill(['state' => ['intent' => ['query' => 'Неизвестная модель', 'year' => 1995]]])->save();
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertArrayNotHasKey('car_model_id', $context['intent']);
        $this->assertSame(1995, $context['intent']['year']);
        app(ShoppingService::class)->get($chat)->forceFill(['state' => ['intent' => ['year' => 1995]]])->save();
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertArrayNotHasKey('options', $context['intent']);
        $this->assertArrayNotHasKey('car_model_id', $context['intent']);
    }

    public function test_year_only_followup_uses_known_vehicle_without_a_model_request(): void
    {
        $model = CarModel::factory()->create(['name' => 'Camry']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldNotReceive('generate');
        $seen = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([['role' => 'user', 'text' => '2018']], $this->account(), null, null, null,
            function ($name, $args) use (&$seen) {
                $seen = $args;
            }, ['intent' => ['car_model_id' => $model->id, 'query' => 'Camry', 'year' => 2018]]);
        $this->assertSame($model->id, $seen['car_model_id']);
        $this->assertSame(2018, $seen['year']);
    }

    public function test_shared_cart_preserves_unresolved_lines_and_current_quantities(): void
    {
        config(['ordering.storefront_core' => true]);
        session(['ordering.guest_token' => str_repeat('a', 64)]);
        $f = $this->fixture();
        app(ActiveDraft::class)->bind($f['buyer'], $f['draft']->id);
        $drafts = app(DraftService::class);
        $items = $f['draft']->jsonSerialize()['items'];
        $items = array_map(fn ($item) => array_intersect_key($item, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed'])), $items);
        $items[] = ['line_id' => (string) Str::uuid(), 'reference' => 'UNRESOLVED-TEST', 'quantity' => 2];
        $drafts->update($f['buyer'], $f['draft']->id, $f['draft']->version, ['items' => $items]);
        $cart = new Cart(session()->driver());
        $this->assertSame(3, $cart->count());
        $cart->add($f['offer']->product, 1, $f['offer']->id);
        $current = $drafts->get($f['buyer'], $f['draft']->id)->jsonSerialize();
        $this->assertCount(2, $current['items']);
        $this->assertSame(4, collect($current['items'])->firstWhere('offer_id', $f['offer']->id)['quantity']);
        $this->assertNotNull(collect($current['items'])->firstWhere('reference', 'UNRESOLVED-TEST'));
    }

    public function test_new_conversation_does_not_erase_known_options_or_their_provenance(): void
    {
        $buyer = $this->account();
        $memory = app(MemoryService::class);
        $version = $memory->consent($buyer, true)->version;
        $model = CarModel::factory()->create(['name' => 'Camry']);
        $id = (string) Str::uuid();
        $memory->save($buyer, ['city' => 'Караганда', 'vehicles' => [['id' => $id, 'label' => 'Camry', 'car_model_id' => $model->id, 'year' => 2018, 'options' => ['camera' => true, 'hud' => false]]]], $version);
        $before = $memory->get($buyer)->facts;
        $memory->remember($buyer, ['car_model_id' => $model->id, 'year' => 2018], false);
        $this->assertSame(['camera' => true, 'hud' => false], $memory->values($buyer)['vehicles'][0]['options']);
        $this->assertSame($before['city'], $memory->get($buyer)->facts['city']);
        $this->assertSame($before['vehicles']['subjects'][$id]['options.camera'], $memory->get($buyer)->facts['vehicles']['subjects'][$id]['options.camera']);
        $memory->remember($buyer, ['car_model_id' => $model->id, 'year' => 2018, 'options' => ['camera' => null]], false);
        $this->assertNull($memory->values($buyer)['vehicles'][0]['options']['camera']);
        $this->assertFalse($memory->values($buyer)['vehicles'][0]['options']['hud']);
        $this->assertSame('unknown', $memory->get($buyer)->facts['vehicles']['subjects'][$id]['options.camera']['status']);
    }

    public function test_receiving_words_do_not_hide_the_explicit_vehicle_and_correction_reuses_it(): void
    {
        $buyer = $this->account();
        $model = CarModel::factory()->create(['name' => 'Camry 70']);
        $store = app(ChatStore::class);
        $chat = $store->create($buyer);
        $turn = $store->enqueue($chat, 'Camry 70 2018 лобовое с камерой, доставка Караганда, Тестовая 1');
        $store->write($turn, 'Уточните комплектацию', 'completed');
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertSame($model->id, $context['intent']['car_model_id']);
        $store->enqueue($chat, 'Ошибся, камеры нет');
        $context = app(ShoppingService::class)->context($chat, $buyer);
        $this->assertFalse($context['intent']['options']['camera']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldNotReceive('generate');
        $args = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([['role' => 'user', 'text' => 'Ошибся, камеры нет']], $buyer, null, null, null,
            function ($name, $parameters) use (&$args) {
                $args = $parameters;
            }, $context);
        $this->assertSame('Camry 70', $args['query']);
        $this->assertSame($model->id, $args['car_model_id']);
        $this->assertFalse($args['options']['camera']);
    }

    public function test_option_button_invalidates_the_linked_purchase_selection(): void
    {
        $f = $this->fixture();
        $chat = app(ChatStore::class)->create($f['buyer']);
        app(ShoppingService::class)->get($chat)->forceFill(['draft_id' => $f['draft']->id,
            'state' => ['intent' => ['year' => 2018], 'awaiting_option' => 'camera', 'selected' => [$f['offer']->id]]])->save();
        app(ShoppingService::class)->answerOption($chat, $f['buyer'], 'camera', null);
        $draft = app(DraftService::class)->get($f['buyer'], $f['draft']->id)->jsonSerialize();
        $this->assertSame($f['draft']->version + 1, $draft['version']);
        $this->assertSame([], $draft['items']);
        $this->assertArrayHasKey('camera', $draft['vehicle']['options']);
        $this->assertNull($draft['vehicle']['options']['camera']);
    }

    public function test_saved_vehicle_button_updates_the_linked_draft_and_preserves_ownership(): void
    {
        $buyer = $this->account();
        $model = CarModel::factory()->create(['name' => 'Camry']);
        $id = (string) Str::uuid();
        $memory = app(MemoryService::class);
        $version = $memory->consent($buyer, true)->version;
        $memory->save($buyer, ['vehicles' => [['id' => $id, 'label' => 'Camry', 'car_model_id' => $model->id, 'year' => 2018, 'options' => ['hud' => false]]]], $version);
        $draft = app(DraftService::class)->create($buyer, SalesMode::Retail);
        $chat = app(ChatStore::class)->create($buyer);
        app(ShoppingService::class)->get($chat)->forceFill(['draft_id' => $draft->id])->save();
        app(ShoppingService::class)->useVehicle($chat, $buyer, $id);
        $current = app(DraftService::class)->get($buyer, $draft->id)->jsonSerialize();
        $this->assertSame(2018, $current['vehicle']['year']);
        $this->assertSame($model->id, $current['vehicle']['model_id']);
        $this->assertFalse($current['vehicle']['options']['hud']);
        $this->expectException(ModelNotFoundException::class);
        app(ShoppingService::class)->useVehicle($chat, $this->account(), $id);
    }

    public function test_memory_form_preserves_the_distinction_between_no_and_unknown_after_reload(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $buyer = BuyerContext::account($user->id);
        $memory = app(MemoryService::class);
        $version = $memory->consent($buyer, true)->version;
        $memory->save($buyer, ['vehicles' => [['id' => (string) Str::uuid(), 'label' => 'Test car',
            'options' => ['camera' => false, 'hud' => true, 'rain_sensor' => null]]]], $version);
        Livewire::actingAs($user)->test(AssistantMemory::class)
            ->assertSet('facts.vehicles.0.options.camera', '0')
            ->assertSet('facts.vehicles.0.options.hud', '1')
            ->assertSet('facts.vehicles.0.options.rain_sensor', '')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['camera' => false, 'hud' => true, 'rain_sensor' => null], $memory->values($buyer)['vehicles'][0]['options']);
    }

    public function test_new_article_lookup_does_not_prepare_the_previous_selected_offer(): void
    {
        $old = Offer::factory()->create(['article' => 'PREVIOUS-001']);
        $next = Offer::factory()->create(['article' => 'NEXT-123']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldNotReceive('generate');
        $seen = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([
            ['role' => 'model', 'text' => 'Найдено', 'tools' => [['name' => 'search_products', 'result' => ['items' => [['offer_id' => $old->id, 'name' => 'Old glass']]]]]],
            ['role' => 'user', 'text' => 'Беру offer_id '.$old->id],
            ['role' => 'user', 'text' => 'Подготовь расчёт для NEXT-123'],
        ], $this->account(), null, null, null, function ($name, $args, $result) use (&$seen) {
            $seen = [$name, $args, $result];
        }, ['intent' => ['offer_id' => $old->id], 'selected' => [$old->id]]);
        $this->assertSame('search_products', $seen[0]);
        $this->assertSame('NEXT-123', $seen[1]['query']);
        $this->assertSame([$next->id], array_column($seen[2]['items'], 'offer_id'));
    }

    public function test_code_with_a_space_uses_the_catalogue_without_a_provider_request(): void
    {
        $offer = Offer::factory()->create(['article' => 'JOURNEY-001']);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldNotReceive('generate');
        $items = [];
        (new AssistantDialog($provider, app(AssistantTools::class)))->reply([['role' => 'user', 'text' => 'JOURNEY 001 кодын тап']],
            $this->account(), null, null, null, function ($name, $args, $result) use (&$items) {
                $items = $result['items'];
            }, ['intent' => []]);
        $this->assertSame([$offer->id], array_column($items, 'offer_id'));
    }
}
