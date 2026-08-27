<?php

namespace App\Filament\Resources\Assignments\Tables;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * План работ на день: кто, куда и к скольки.
 * Сортировка по времени — расписание читают сверху вниз, а не по номерам.
 */
class AssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.customer', 'assignee', 'servicePoint']))
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('Когда')
                    ->dateTime('d.m H:i')
                    ->sortable()
                    ->description(fn (Assignment $r) => $r->duration_minutes.' мин'),

                TextColumn::make('type')
                    ->label('Работа')
                    ->badge()
                    ->formatStateUsing(fn (AssignmentType $state) => $state->label())
                    ->color(fn (AssignmentType $state) => $state === AssignmentType::Installation ? 'info' : 'warning'),

                TextColumn::make('order.number')
                    ->label('Заявка')
                    ->searchable()
                    ->description(fn (Assignment $r) => $r->order->customer->name),

                TextColumn::make('order.customer.phone')
                    ->label('Телефон')
                    ->url(fn (Assignment $r) => 'tel:'.$r->order->customer->phone),

                TextColumn::make('assignee.name')
                    ->label('Исполнитель')
                    ->placeholder('не назначен')
                    ->badge()
                    ->color(fn (?string $state) => $state ? 'primary' : 'warning'),

                TextColumn::make('servicePoint.name')
                    ->label('Место')
                    ->placeholder(fn (Assignment $r) => $r->address ?: '—')
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (AssignmentStatus $state) => $state->label())
                    ->color(fn (AssignmentStatus $state) => $state->color()),
            ])
            ->filters([
                SelectFilter::make('type')->label('Работа')->options(AssignmentType::options()),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->multiple()
                    ->options(AssignmentStatus::options()),

                SelectFilter::make('assignee_id')
                    ->label('Исполнитель')
                    ->relationship('assignee', 'name')
                    ->searchable(),

                Filter::make('today')
                    ->label('На сегодня')
                    ->query(fn (Builder $query) => $query->whereDate('scheduled_at', today())),

                // Наряд без исполнителя за час до начала — это проблема,
                // которую диспетчер должен увидеть раньше клиента.
                Filter::make('unassigned')
                    ->label('Без исполнителя')
                    ->query(fn (Builder $query) => $query->whereNull('assignee_id')->active()),
            ])
            ->defaultSort('scheduled_at')
            ->recordActions([
                Action::make('status')
                    ->label('Статус')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Assignment $r) => ! $r->status->isFinal())
                    ->schema(fn (Assignment $record) => [
                        Select::make('status')
                            ->label('Новый статус')
                            ->options(collect($record->status->allowedTransitions($record->type))
                                ->mapWithKeys(fn (AssignmentStatus $s) => [$s->value => $s->label()]))
                            ->required(),
                    ])
                    ->action(function (Assignment $record, array $data) {
                        $ok = $record->transitionTo(AssignmentStatus::from($data['status']));

                        Notification::make()
                            ->title($ok ? 'Статус обновлён' : 'Такой переход запрещён')
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),

                EditAction::make()->label('Открыть'),
            ])
            ->emptyStateHeading('Нарядов нет')
            ->emptyStateDescription('Наряд создаётся из заявки — кнопкой «Назначить работу».');
    }
}
