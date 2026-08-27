<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\SalesFunnel;
use App\Filament\Widgets\UrgentWork;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrmAdminTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->manager()->create();
    }

    public function test_раздел_задач_открывается(): void
    {
        $this->actingAs($this->manager())
            ->get(TaskResource::getUrl('index'))
            ->assertOk();
    }

    public function test_дашборд_открывается(): void
    {
        $this->actingAs($this->manager())
            ->get('/admin')
            ->assertOk();
    }

    /** Счётчик в меню считает просроченные, а не все открытые. */
    public function test_счётчик_задач_показывает_просроченные(): void
    {
        Task::factory()->overdue()->count(2)->create();
        Task::factory()->count(3)->create();               // срок ещё не наступил
        Task::factory()->overdue()->status(TaskStatus::Done)->create();

        $this->assertSame('2', TaskResource::getNavigationBadge());
    }

    public function test_без_просроченных_счётчик_пуст(): void
    {
        Task::factory()->count(2)->create();

        $this->assertNull(TaskResource::getNavigationBadge());
    }

    /** Воронка показывает живые стадии и не тонет в закрытых заказах. */
    public function test_воронка_считает_только_живые_стадии(): void
    {
        Order::factory()->count(2)->create();                                    // Новая
        Order::factory()->status(OrderStatus::Completed)->count(5)->create();

        Livewire::actingAs($this->manager())
            ->test(SalesFunnel::class)
            ->assertOk()
            ->assertSee('Новая')
            ->assertDontSee('Выполнен');
    }

    public function test_виджет_срочного_показывает_просроченные(): void
    {
        Task::factory()->overdue()->create();

        Livewire::actingAs($this->manager())
            ->test(UrgentWork::class)
            ->assertOk()
            ->assertSee('Просроченные задачи')
            ->assertSee('нужно разобрать сейчас');
    }

    public function test_история_и_задачи_видны_в_карточке_заявки(): void
    {
        $order = Order::factory()->create();
        $order->transitionTo(OrderStatus::InProgress);

        $this->actingAs($this->manager())
            ->get(OrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk();

        $this->assertCount(1, $order->interactions);
    }

    /** Курьеру CRM не положена. */
    public function test_курьер_в_задачи_не_попадает(): void
    {
        $this->actingAs(User::factory()->courier()->create())
            ->get(TaskResource::getUrl('index'))
            ->assertForbidden();
    }
}
