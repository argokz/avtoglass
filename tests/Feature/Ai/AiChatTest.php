<?php

namespace Tests\Feature\Ai;

use App\Enums\OrderSource;
use App\Livewire\AiChat;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Чат с AI-помощником: витрина, инструменты, заявка.
 * Gemini подменяется Http::fake — сеть в тестах не ходит.
 */
class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private function включить(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => ['test-key']]);
    }

    private function текст(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function вызов(string $name, array $args): array
    {
        return ['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]]]]];
    }

    public function test_по_умолчанию_чата_нет_на_витрине(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Чат с помощником');
    }

    public function test_чат_появляется_когда_включён_и_есть_ключ(): void
    {
        $this->включить();

        $this->get(route('home'))
            ->assertOk()
            ->assertViewIs('home')
            ->assertSee('Войти')
            ->assertSee('ИИ-помощник')
            ->assertSee('Стекло на какую машину?')
            ->assertDontSee('Чат с помощником')
            ->assertSee('напишите помощнику');
    }

    public function test_страница_чата_открывается(): void
    {
        $this->включить();

        $this->get(route('assistant'))
            ->assertOk()
            ->assertSee('Camry 2018, лобовое')
            ->assertSee('Помогу подобрать стекло');
    }

    public function test_чат_не_уходит_get_формой(): void
    {
        $this->включить();

        $html = $this->get(route('assistant'))->assertOk()->getContent();

        $this->assertStringContainsString('wire:click="send"', $html);
        $this->assertStringContainsString('x-on:keydown.enter=', $html);
        $this->assertStringNotContainsString('wire:submit', $html);
        $this->assertStringNotContainsString('<form action="'.route('search').'"', $html);
    }

    public function test_без_ключа_страница_чата_закрыта(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => []]);

        $this->get(route('assistant'))->assertNotFound();
    }

    public function test_без_ключа_чат_не_рисуется_даже_когда_включён(): void
    {
        settings()->save(['ai_assistant' => true]);
        config(['ai.keys' => []]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Чат с помощником');
    }

    public function test_простой_ответ(): void
    {
        $this->включить();
        Http::fake(fn () => Http::response($this->текст('Здравствуйте! Что за машина?')));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Привет')
            ->call('send')
            ->assertSee('Здравствуйте! Что за машина?');
    }

    public function test_поиск_через_инструмент_возвращает_цену_витрины(): void
    {
        $this->включить();

        $product = Product::factory()->create(['name' => 'Лобовое стекло Toyota Camry XV70']);
        Offer::factory()->create([
            'product_id' => $product->id,
            'price_retail' => 45000,
            'price_wholesale' => 30000,
        ]);
        $product->refreshPricing();

        Http::fakeSequence()
            ->push($this->вызов('search_products', ['query' => 'camry']))
            ->push($this->текст('Есть лобовое на Camry XV70 — 45 000 ₸, под заказ. Оформить?'));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Нужно лобовое на Camry')
            ->call('send')
            ->assertSee('45 000')
            ->assertSee('Оформить?');
    }

    public function test_заявка_создаётся_через_инструмент(): void
    {
        $this->включить();

        $product = Product::factory()->create(['name' => 'Лобовое стекло Toyota Camry XV70']);
        Offer::factory()->create([
            'product_id' => $product->id,
            'price_retail' => 45000,
            'price_wholesale' => 30000,
        ]);
        $product->refreshPricing();

        Http::fakeSequence()
            ->push($this->вызов('create_order', [
                'name' => 'Айгерим',
                'phone' => '+7 700 123-45-67',
                'product_id' => $product->id,
                'fulfillment' => 'installation',
                'privacy_confirmed' => true,
            ]))
            ->push($this->текст('Готово! Заявка принята, менеджер перезвонит.'));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Оформляй, согласен на обработку данных')
            ->call('send')
            ->assertSee('Заявка принята');

        $order = Order::sole();
        $this->assertSame(OrderSource::Assistant, $order->source);
        $this->assertSame('Айгерим', $order->customer->name);
        $this->assertSame($product->id, $order->items()->sole()->product_id);
    }

    public function test_без_согласия_заявка_не_создаётся(): void
    {
        $this->включить();

        Http::fakeSequence()
            ->push($this->вызов('create_order', [
                'name' => 'Айгерим',
                'phone' => '+7 700 123-45-67',
                'privacy_confirmed' => false,
            ]))
            ->push($this->текст('Подтвердите, пожалуйста, согласие на обработку персональных данных.'));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Оформи')
            ->call('send')
            ->assertSee('согласие на обработку');

        $this->assertSame(0, Order::count());
    }

    public function test_частота_сообщений_ограничена(): void
    {
        $this->включить();
        config(['ai.rate_limit_per_minute' => 2]);
        Http::fake(fn () => Http::response($this->текст('Ответ')));

        $chat = Livewire::test(AiChat::class)->set('open', true);

        $chat->set('draft', 'раз')->call('send');
        $chat->set('draft', 'два')->call('send');
        $chat->set('draft', 'три')->call('send')
            ->assertSee('подождите минуту');
    }

    public function test_история_остаётся_после_нового_монтажа(): void
    {
        $this->включить();
        Http::fake(fn () => Http::response($this->текст('Какая машина?')));

        $chat = Livewire::test(AiChat::class, ['embedded' => true])
            ->set('draft', 'Camry 2018')
            ->call('send')
            ->assertSee('Camry 2018')
            ->assertSee('Какая машина?');

        $this->assertSame($chat->get('messages'), session(AiChat::SESSION_KEY));

        Livewire::test(AiChat::class, ['embedded' => true])
            ->assertSee('Camry 2018')
            ->assertSee('Какая машина?');
    }

    public function test_когда_все_лимиты_исчерпаны_клиент_получает_понятный_ответ(): void
    {
        $this->включить();
        Http::fake(fn () => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Привет')
            ->call('send')
            ->assertSee('на паузе');
    }

    public function test_перегрузка_gemini_не_роняет_чат(): void
    {
        $this->включить();
        Http::fake(fn () => Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'high demand']], 503));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Привет')
            ->call('send')
            ->assertSee('на паузе')
            ->assertDontSee('high demand');
    }

    public function test_отказ_gemini_не_даёт_500(): void
    {
        $this->включить();
        Http::fake(fn () => Http::response(['error' => ['message' => 'API key not valid']], 400));

        Livewire::test(AiChat::class)
            ->set('open', true)
            ->set('draft', 'Привет')
            ->call('send')
            ->assertSee('Не получилось ответить')
            ->assertDontSee('API key not valid');
    }
}
