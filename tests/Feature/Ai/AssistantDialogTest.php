<?php

namespace Tests\Feature\Ai;

use App\Ai\AiProviderException;
use App\Ai\AssistantDialog;
use App\Ai\AssistantTools;
use App\Ai\Providers\ChatProvider;
use App\Domain\Identity\BuyerContext;
use App\Settings\AppSettings;
use Mockery;
use Tests\TestCase;

/** These contract tests mock settings and tools and do not access the database. */
class AssistantDialogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = Mockery::mock(AppSettings::class);
        $settings->shouldReceive('brandName')->andReturn('Test');
        $settings->shouldReceive('defaultCity')->andReturn('Алматы');
        $settings->shouldReceive('phones')->andReturn([]);
        $settings->shouldReceive('cities')->andReturn(['Караганда', 'Алматы']);
        $this->app->instance(AppSettings::class, $settings);
    }

    public function test_repeated_tool_is_stopped_without_duplicate_write(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $tools = Mockery::mock(AssistantTools::class);
        $call = [['functionCall' => ['name' => 'prepare_order', 'args' => ['name' => 'Test']]]];
        $buyer = BuyerContext::guest(str_repeat('a', 64), 'kk');
        $provider->shouldReceive('generate')->twice()->andReturn($call);
        $tools->shouldReceive('call')->once()->with('prepare_order', ['name' => 'Test'], $buyer, 'turn-test')->andReturn(['ok' => true]);
        $this->expectExceptionMessage('repeated_tool');
        (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Дайындаңыз']], $buyer, null, null, 'turn-test');
    }

    public function test_cancel_after_model_response_prevents_tool_write(): void
    {
        $cancelled = false;
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturnUsing(function () use (&$cancelled) {
            $cancelled = true;

            return [['functionCall' => ['name' => 'prepare_order', 'args' => []]]];
        });
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldNotReceive('call');
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('cancelled');
        (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Подготовьте']], null, null, function () use (&$cancelled) {
            return $cancelled;
        });
    }

    public function test_history_is_bounded_and_preserves_latest_question(): void
    {
        config(['ai.context_characters' => 100]);
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($contents, $prompt) {
            $texts = array_column(array_column($contents, 'parts'), 0);
            $this->assertLessThanOrEqual(100, mb_strlen(implode('', array_column($texts, 'text'))));
            $this->assertSame('Последний вопрос', end($contents)['parts'][0]['text']);
            $this->assertStringContainsString('ADAS', $prompt);

            return true;
        })->andReturn([['text' => 'Ответ']]);
        $tools = Mockery::mock(AssistantTools::class);
        $messages = array_fill(0, 50, ['role' => 'user', 'text' => str_repeat('А', 100)]);
        $messages[] = ['role' => 'user', 'text' => 'Последний вопрос'];
        $this->assertSame('Ответ', (new AssistantDialog($provider, $tools))->reply($messages));
    }

    public function test_saved_offer_context_is_replayed_as_native_tool_result(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($contents) {
            $this->assertSame('search_products', $contents[0]['parts'][0]['functionCall']['name']);
            $this->assertSame(123, $contents[1]['parts'][0]['functionResponse']['response']['items'][0]['offer_id']);
            $this->assertSame('Выбираю первый', $contents[2]['parts'][0]['text']);

            return true;
        })->andReturn([['text' => 'Уточните имя и телефон']]);
        $tools = Mockery::mock(AssistantTools::class);
        $messages = [
            ['role' => 'tool_context', 'tools' => [['name' => 'search_products', 'args' => ['year' => 2020], 'result' => ['items' => [['offer_id' => 123, 'price_kzt' => '30000.00']]]]]],
            ['role' => 'user', 'text' => 'Выбираю первый'],
        ];
        $this->assertSame('Уточните имя и телефон', (new AssistantDialog($provider, $tools))->reply($messages));
    }

    public function test_search_uses_customer_year_and_returns_grounded_reply_without_second_generation(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([
            ['functionCall' => ['name' => 'search_products', 'args' => ['query' => 'RAV4', 'year' => 2019]]],
        ]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldReceive('call')->once()->withArgs(function ($name, $args) {
            return $name === 'search_products' && $args['year'] === 1995 && $args['glass_type'] === 'windshield';
        })->andReturn(['items' => [['offer_id' => 1, 'name' => 'RAV4 1994–2000', 'fitment' => ['status' => 'review_required']]]]);
        $reply = (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Toyota RAV4 1995, лобовое']]);
        $this->assertStringContainsString('Совместимость пока требует проверки', $reply);
        $this->assertStringNotContainsString('телефон', $reply);
        $this->assertStringContainsString('конкретную карточку', $reply);
    }

    public function test_unsupported_catalogue_claim_is_never_published_to_chunk_callback(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([['text' => 'RAV4 подходит, цена 999 тенге, артикул MADE-UP.']]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldNotReceive('call');
        $chunks = [];
        $reply = (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Что есть?']], null,
            function ($text) use (&$chunks) {
                $chunks[] = $text;
            });
        $this->assertSame([$reply], $chunks);
        $this->assertStringNotContainsString('999', $reply);
        $this->assertStringNotContainsString('MADE-UP', $reply);
    }

    public function test_model_cannot_prepare_an_offer_before_explicit_selection(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->twice()->andReturn(
            [['functionCall' => ['name' => 'prepare_order', 'args' => ['offer_id' => 12]]]],
            [['text' => 'Выберите карточку']]
        );
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldNotReceive('call');
        $reply = (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Нужен RAV4']]);
        $this->assertSame('Выберите карточку', $reply);
    }

    public function test_selected_offer_prepares_a_card_without_second_model_response(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([
            ['functionCall' => ['name' => 'prepare_order', 'args' => ['offer_id' => 12]]],
        ]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldReceive('call')->once()->andReturn(['ok' => true, 'checkout' => ['id' => 'card']]);
        $history = [
            ['role' => 'tool_context', 'tools' => [['name' => 'search_products', 'result' => ['items' => [['offer_id' => 12]]]]]],
            ['role' => 'user', 'text' => 'Беру offer_id 12'],
            ['role' => 'user', 'text' => 'Контакты уже указаны, подготовьте'],
        ];
        $reply = (new AssistantDialog($provider, $tools))->reply($history);
        $this->assertStringContainsString('Заявка подготовлена', $reply);
        $this->assertStringContainsString('подтвердите отправку кнопкой', $reply);
    }

    public function test_model_cannot_claim_that_it_submitted_an_order(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([['text' => 'Ваш заказ оформлен!']]);
        $tools = Mockery::mock(AssistantTools::class);
        $reply = (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Оформляй']]);
        $this->assertStringNotContainsString('оформлен', $reply);
        $this->assertStringContainsString('кнопкой подтверждения', $reply);
    }

    public function test_delivery_address_and_vehicle_year_survive_selection_and_override_model_checkout(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($contents, $prompt) {
            $this->assertStringNotContainsString('куйбышева 15', $prompt);
            $this->assertStringContainsString('without_installation', $prompt);

            return true;
        })->andReturn([['functionCall' => ['name' => 'prepare_order', 'args' => [
            'offer_id' => 12, 'year' => 1994, 'fulfillment' => 'installation',
            'city' => 'Алматы', 'slot_start' => 'invented', 'slot_end' => 'invented', 'service_point_id' => 9,
        ]]]]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldReceive('call')->once()->withArgs(function ($name, $args) {
            $this->assertSame('delivery', $args['fulfillment']);
            $this->assertSame('Караганда', $args['city']);
            $this->assertSame('куйбышева 15', $args['address']);
            $this->assertSame(1995, $args['year']);
            $this->assertStringContainsString('Без установки', $args['comment']);
            $this->assertArrayNotHasKey('slot_start', $args);
            $this->assertArrayNotHasKey('service_point_id', $args);

            return $name === 'prepare_order';
        })->andReturn(['ok' => true, 'checkout' => ['id' => 'card']]);
        $history = [
            ['role' => 'user', 'text' => 'Лобовое стекло RAV4 1995 года без установки доставка Караганда, куйбышева 15'],
            ['role' => 'tool_context', 'tools' => [['name' => 'search_products', 'args' => [], 'result' => ['items' => [['offer_id' => 12]]]]]],
            ['role' => 'user', 'text' => 'Беру offer_id 12: Лобовое стекло Toyota RAV4 1994–2000 (ЗП)'],
            ['role' => 'user', 'text' => 'Имя Тест, телефон 77001234567'],
        ];
        $reply = (new AssistantDialog($provider, $tools))->reply($history);
        $this->assertStringContainsString('Заявка подготовлена', $reply);
    }

    public function test_search_reply_acknowledges_delivery_and_separates_sentences(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([['functionCall' => ['name' => 'search_products', 'args' => ['query' => 'RAV4']]]]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldReceive('call')->once()->andReturn(['items' => [['offer_id' => 12, 'fitment' => ['status' => 'review_required']]]]);
        $reply = (new AssistantDialog($provider, $tools))->reply([['role' => 'user', 'text' => 'Лобовое стекло RAV4 1995 года без установки доставка Караганда, куйбышева 15']]);
        $this->assertStringContainsString('Получение: доставка, Караганда, куйбышева 15.', $reply);
        $this->assertStringContainsString('Без установки.', $reply);
        $this->assertStringContainsString('. Совместимость', $reply);
        $this->assertStringContainsString('. Выберите', $reply);
    }

    public function test_old_wrong_year_card_cannot_be_prepared_after_customer_selection(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->twice()->andReturn(
            [['functionCall' => ['name' => 'prepare_order', 'args' => ['offer_id' => 12]]]],
            [['text' => 'Выберите карточку для указанного года']]
        );
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldNotReceive('call');
        $history = [
            ['role' => 'user', 'text' => 'RAV4 1995, без установки'],
            ['role' => 'tool_context', 'tools' => [['name' => 'search_products', 'args' => [], 'result' => ['items' => [
                ['offer_id' => 12, 'name' => 'Лобовое стекло Toyota RAV4 2019'],
            ]]]]],
            ['role' => 'user', 'text' => 'Беру offer_id 12: Лобовое стекло Toyota RAV4 2019'],
        ];
        $reply = (new AssistantDialog($provider, $tools))->reply($history);
        $this->assertStringContainsString('для указанного года', $reply);
    }

    public function test_kazakh_search_reply_translates_option_labels_and_unresolved_request_prepares_card(): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn([
            ['functionCall' => ['name' => 'search_products', 'args' => ['query' => 'Camry']]],
        ]);
        $tools = Mockery::mock(AssistantTools::class);
        $tools->shouldReceive('call')->once()->andReturn([
            'items' => [[
                'offer_id' => 1,
                'name' => 'Toyota Camry 2018',
                'missing_options' => ['camera', 'rain_sensor', 'heated_full', 'antenna'],
                'fitment' => ['status' => 'review_required'],
            ]],
        ]);
        $buyer = BuyerContext::guest(str_repeat('a', 64), 'kk');
        $reply = (new AssistantDialog($provider, $tools))->reply([
            ['role' => 'user', 'text' => 'Camry 2018 алдыңғы әйнек'],
        ], $buyer);
        $this->assertDoesNotMatchRegularExpression('/Камера|Датчик|Обогрев|Антенна|крепление/iu', $reply);
        $this->assertStringContainsString('ADAS бейнебақылау сенсоры', $reply);

        $provider2 = Mockery::mock(ChatProvider::class);
        $provider2->shouldReceive('generate')->once()->andReturn([
            ['functionCall' => ['name' => 'search_products', 'args' => ['query' => 'RAV4', 'year' => 1995]]],
        ]);
        $tools2 = Mockery::mock(AssistantTools::class);
        $tools2->shouldReceive('call')->once()->withArgs(fn ($name) => $name === 'search_products')->andReturn(['items' => []]);
        $tools2->shouldReceive('call')->once()->withArgs(function ($name, $args) {
            return $name === 'prepare_order'
                && ($args['name'] ?? '') === 'Демо Аудит'
                && ($args['phone'] ?? '') === '+77000000003'
                && ($args['fulfillment'] ?? '') === 'pickup'
                && ($args['city'] ?? '') === 'Караганда'
                && ! empty($args['reference']);
        })->andReturn(['ok' => true, 'checkout' => ['id' => 'unresolved-card']]);
        $unresolvedReply = (new AssistantDialog($provider2, $tools2))->reply([
            ['role' => 'user', 'text' => 'Подготовь заявку менеджеру на подбор RAV4 1995 лобового стекла. Демо Аудит, +77000000003, самовывоз Караганда. Отправку подтвержу кнопкой.'],
        ], BuyerContext::guest(str_repeat('b', 64), 'ru'), null, null, 'turn-unresolved');
        $this->assertStringContainsString('Заявка подготовлена', $unresolvedReply);
    }
}
