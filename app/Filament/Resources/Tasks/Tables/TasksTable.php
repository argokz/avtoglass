<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Список задач менеджера. Сортировка по сроку, а не по дате создания:
 * открывают его затем, чтобы понять, что горит прямо сейчас.
 */
class TasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'order', 'assignee']))
            ->columns([
                TextColumn::make('due_at')
                    ->label('Срок')
                    ->dateTime('d.m H:i')
                    ->sortable()
                    // Просроченное должно быть видно, не вчитываясь в даты
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null)
                    ->weight(fn (Task $r) => $r->isOverdue() ? 'bold' : null)
                    ->description(fn (Task $r) => $r->isOverdue() ? 'просрочена' : null),

                TextColumn::make('title')
                    ->label('Задача')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('customer.name')
                    ->label('Клиент')
                    ->searchable()
                    ->description(fn (Task $r) => $r->customer?->phone)
                    ->url(fn (Task $r) => $r->customer ? 'tel:'.$r->customer->phone : null)
                    ->placeholder('—'),

                TextColumn::make('order.number')
                    ->label('Заявка')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('assignee.name')
                    ->label('Исполнитель')
                    ->badge()
                    ->color(fn (?string $state) => $state ? 'primary' : 'warning')
                    ->placeholder('ничья'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
            ])
            ->filters([
                Filter::make('overdue')
                    ->label('Просроченные')
                    ->query(fn (Builder $query) => $query->overdue()),

                Filter::make('today')
                    ->label('На сегодня')
                    ->query(fn (Builder $query) => $query->dueToday()),

                Filter::make('mine')
                    ->label('Мои')
                    ->query(fn (Builder $query) => $query->where('assigned_to', Auth::id())),

                Filter::make('unassigned')
                    ->label('Ничьи')
                    ->query(fn (Builder $query) => $query->open()->whereNull('assigned_to')),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(TaskStatus::options()),
            ])
            ->defaultSort('due_at')
            ->recordActions([
                Action::make('take')
                    ->label('Взять')
                    ->icon('heroicon-o-hand-raised')
                    ->visible(fn (Task $r) => $r->assigned_to === null && $r->status->isOpen())
                    ->action(function (Task $record) {
                        $record->update(['assigned_to' => Auth::id()]);

                        Notification::make()->title('Задача ваша')->success()->send();
                    }),

                Action::make('complete')
                    ->label('Выполнена')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Task $r) => $r->status->isOpen())
                    ->schema([
                        Textarea::make('result')
                            ->label('Что вышло')
                            ->rows(3)
                            ->helperText('Договорились о чём-то? Напишите — попадёт в историю клиента'),
                    ])
                    ->action(function (Task $record, array $data) {
                        $record->complete($data['result'] ?? null);

                        Notification::make()->title('Закрыта')->success()->send();
                    }),

                Action::make('cancel')
                    ->label('Отменить')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (Task $r) => $r->status->isOpen())
                    ->schema([
                        Textarea::make('result')->label('Причина')->rows(2)->required(),
                    ])
                    ->action(fn (Task $record, array $data) => $record->cancel($data['result'])),

                EditAction::make()->label('Открыть'),
            ])
            ->emptyStateHeading('Задач нет')
            ->emptyStateDescription('Задачи появляются автоматически по новым заявкам и создаются вручную.');
    }
}
