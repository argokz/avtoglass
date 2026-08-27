<?php

namespace Tests\Feature;

use App\Enums\InteractionType;
use App\Enums\OrderStatus;
use App\Enums\TaskStatus;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // журнал
    // ------------------------------------------------------------------

    /**
     * Главное свойство журнала: он пишется сам. Статус меняют из списка,
     * из карточки и из планировщика — в каждом месте про запись забудут.
     */
    public function test_смена_статуса_попадает_в_журнал_сама(): void
    {
        $order = Order::factory()->create();

        $order->transitionTo(OrderStatus::InProgress);

        $entry = $order->interactions()->sole();

        $this->assertSame(InteractionType::System, $entry->type);
        $this->assertStringContainsString('Новая', $entry->body);
        $this->assertStringContainsString('В работе', $entry->body);
    }

    public function test_запрещённый_переход_журнал_не_засоряет(): void
    {
        $order = Order::factory()->status(OrderStatus::Completed)->create();

        $order->transitionTo(OrderStatus::New);

        $this->assertCount(0, $order->interactions);
    }

    /** Кто нажал — тоже часть ответа на вопрос «почему мы потеряли клиента». */
    public function test_журнал_запоминает_автора(): void
    {
        $manager = User::factory()->manager()->create();
        $order = Order::factory()->create();

        $this->actingAs($manager);
        $order->transitionTo(OrderStatus::InProgress);

        $this->assertSame($manager->id, $order->interactions()->sole()->user_id);
    }

    /** В карточке клиента нужна переписка, а не системный шум. */
    public function test_человеческие_записи_отделены_от_системных(): void
    {
        $customer = Customer::factory()->create();

        Interaction::factory()->for($customer)->type(InteractionType::CallIn)->create();
        Interaction::factory()->for($customer)->type(InteractionType::System)->create();

        $this->assertSame(2, $customer->interactions()->count());
        $this->assertSame(1, $customer->interactions()->humanOnly()->count());
    }

    public function test_системный_тип_нельзя_выбрать_руками(): void
    {
        $this->assertArrayNotHasKey('system', InteractionType::manualOptions());
        $this->assertArrayHasKey('call_in', InteractionType::manualOptions());
    }

    // ------------------------------------------------------------------
    // задачи
    // ------------------------------------------------------------------

    /** Заявка с сайта обязана порождать задачу — иначе про неё забудут. */
    public function test_заявка_с_сайта_создаёт_задачу_перезвонить(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), [
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ]);

        $order = Order::sole();
        $task = Task::sole();

        $this->assertSame($order->id, $task->order_id);
        $this->assertSame(TaskStatus::Open, $task->status);
        $this->assertStringContainsString($order->number, $task->title);
    }

    /** Срок — минуты, а не «сегодня»: кто перезвонил первым, тот и продал. */
    public function test_задача_ставится_на_ближайшие_минуты(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), [
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ]);

        $this->assertSame('2026-09-01 10:15:00', Task::sole()->due_at->format('Y-m-d H:i:s'));
    }

    /** Задача создаётся ничьей: менеджера у новой заявки ещё нет. */
    public function test_задача_создаётся_без_исполнителя(): void
    {
        $product = Product::factory()->create();

        $this->post(route('order.store', $product), [
            'name' => 'Данил',
            'phone' => '+7 700 123-45-67',
        ]);

        $this->assertNull(Task::sole()->assigned_to);
    }

    public function test_просроченными_считаются_только_открытые(): void
    {
        Task::factory()->overdue()->create();
        Task::factory()->overdue()->status(TaskStatus::Done)->create();
        Task::factory()->create();   // срок через час

        $this->assertSame(1, Task::overdue()->count());
    }

    public function test_задачи_на_сегодня_не_включают_завтрашние(): void
    {
        Task::factory()->dueAt('2026-09-01 18:00:00')->create();
        Task::factory()->dueAt('2026-09-02 09:00:00')->create();

        $this->assertSame(1, Task::dueToday()->count());
    }

    public function test_выполнение_задачи_проставляет_дату_и_результат(): void
    {
        $task = Task::factory()->create();

        $this->assertTrue($task->complete('Дозвонился, ждёт счёт'));

        $task->refresh();

        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame('Дозвонился, ждёт счёт', $task->result);
    }

    /** Закрытую задачу нельзя закрыть повторно — иначе теряется первая дата. */
    public function test_повторное_закрытие_отклоняется(): void
    {
        $task = Task::factory()->create();
        $task->complete('первый результат');

        $this->assertFalse($task->fresh()->complete('второй результат'));
        $this->assertSame('первый результат', $task->fresh()->result);
    }

    public function test_отменённая_задача_не_считается_просроченной(): void
    {
        $task = Task::factory()->overdue()->create();
        $task->cancel('клиент передумал');

        $this->assertFalse($task->fresh()->isOverdue());
        $this->assertSame(0, Task::overdue()->count());
    }
}
