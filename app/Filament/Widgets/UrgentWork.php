<?php

namespace App\Filament\Widgets;

use App\Enums\AssignmentStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Assignment;
use App\Models\Order;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Что горит прямо сейчас. Каждая цифра здесь — это что-то, что уже пошло
 * не так или пойдёт в ближайший час, поэтому виджет стоит выше воронки.
 */
class UrgentWork extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    /**
     * Обновляется само.
     *
     * Смысл этого виджета в том, чтобы менеджер увидел новую заявку, не нажимая
     * F5: пятнадцатиминутный срок на звонок не работает, если о заявке узнают
     * при следующем заходе в админку.
     */
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $overdue = Task::overdue()->count();
        $today = Task::dueToday()->count();

        // Ничья заявка — та, за которую никто не взялся. Это самая дорогая
        // категория: клиент уже написал, а ему ещё не ответили.
        $unassignedOrders = Order::whereNull('manager_id')
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->count();

        $unassignedWork = Assignment::whereNull('assignee_id')
            ->whereNotIn('status', [AssignmentStatus::Done->value, AssignmentStatus::Failed->value])
            ->count();

        return [
            Stat::make('Просроченные задачи', (string) $overdue)
                ->description($overdue > 0 ? 'нужно разобрать сейчас' : 'чисто')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->url(TaskResource::getUrl('index')),

            Stat::make('Задачи на сегодня', (string) $today)
                ->color($today > 0 ? 'warning' : 'gray')
                ->url(TaskResource::getUrl('index')),

            Stat::make('Заявки без менеджера', (string) $unassignedOrders)
                ->description($unassignedOrders > 0 ? 'клиент ждёт ответа' : 'все разобраны')
                ->color($unassignedOrders > 0 ? 'danger' : 'success'),

            Stat::make('Наряды без исполнителя', (string) $unassignedWork)
                ->color($unassignedWork > 0 ? 'warning' : 'gray'),
        ];
    }
}
