<?php

namespace Tests\Feature;

use App\Ai\AiProviderException;
use App\Ai\ManagerOrderBrief;
use App\Ai\Providers\ChatProvider;
use App\Enums\InteractionType;
use App\Filament\Resources\Orders\Actions\OrderBriefAction;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** ИИ в карточке заказа: предлагает сводку и черновик, сохраняет менеджер. */
class ManagerOrderBriefTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT = '```json
{"summary": "Клиент ждёт лобовое на Camry.", "reply": "Здравствуйте! Стекло подобрали, срок уточню сегодня."}
```';

    private function модель(?string $ответ = self::DRAFT): void
    {
        $provider = Mockery::mock(ChatProvider::class);
        $ответ === null
            ? $provider->shouldReceive('generate')->andThrow(new AiProviderException('unavailable', true))
            : $provider->shouldReceive('generate')->andReturn([['text' => $ответ]]);
        $this->app->instance(ChatProvider::class, $provider);
    }

    private function заказ(): Order
    {
        $customer = Customer::factory()->create(['name' => 'Айгерим Нурланова', 'phone' => '77015554433']);

        return Order::factory()->create(['customer_id' => $customer->id, 'comment' => 'Перезвоните на +7 701 555 44 33, лобовое на Camry']);
    }

    private function открытьКарточку(Order $order)
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::actingAs(User::factory()->manager()->create())
            ->test(EditOrder::class, ['record' => $order->getRouteKey()]);
    }

    public function test_черновик_разбирается_из_ответа_в_рамке_json(): void
    {
        $this->assertSame('Клиент ждёт лобовое на Camry.', ManagerOrderBrief::parse(self::DRAFT)['summary']);

        $this->expectException(AiProviderException::class);
        ManagerOrderBrief::parse('Конечно! Вот сводка: всё хорошо.');
    }

    public function test_в_модель_не_уходят_телефон_и_имя_клиента(): void
    {
        $order = $this->заказ();
        Interaction::factory()->create(['customer_id' => $order->customer_id, 'order_id' => $order->id, 'type' => InteractionType::CallIn, 'body' => 'Просил перезвонить на 8 701 555 44 33']);

        $context = app(ManagerOrderBrief::class)->context($order);

        $this->assertStringContainsString('лобовое на Camry', $context);
        $this->assertStringNotContainsString('555', $context);
        $this->assertStringNotContainsString('Айгерим', $context);
    }

    public function test_менеджер_сохраняет_черновик_без_правок(): void
    {
        $this->модель();
        $order = $this->заказ();

        $this->открытьКарточку($order)
            ->mountAction(OrderBriefAction::NAME)
            ->assertActionDataSet(['reply' => 'Здравствуйте! Стекло подобрали, срок уточню сегодня.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $note = Interaction::query()->where('order_id', $order->id)->sole();
        $this->assertSame(InteractionType::Note, $note->type);
        $this->assertStringContainsString('без правок', $note->body);
    }

    public function test_правка_черновика_отмечается_в_истории(): void
    {
        $this->модель();
        $order = $this->заказ();

        $this->открытьКарточку($order)
            ->callAction(OrderBriefAction::NAME, ['reply' => 'Здравствуйте! Стекло будет завтра к обеду.'])
            ->assertHasNoActionErrors();

        $this->assertStringContainsString("с правками):\nЗдравствуйте! Стекло будет завтра к обеду.",
            Interaction::query()->where('order_id', $order->id)->sole()->body);
    }

    public function test_если_ии_молчит_окно_открывается_пустым_и_ничего_не_пишет(): void
    {
        $this->модель(null);
        $order = $this->заказ();

        $this->открытьКарточку($order)
            ->mountAction(OrderBriefAction::NAME)
            ->assertActionDataSet(['reply' => '', 'summary' => 'Сводки нет: ИИ не ответил.'])
            ->assertNotified('ИИ сейчас не ответил');

        $this->assertDatabaseCount('interactions', 0);
    }
}
