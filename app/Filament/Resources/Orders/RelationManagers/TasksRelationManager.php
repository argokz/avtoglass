<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\TaskStatus;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Задачи';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Что сделать')->required()->columnSpanFull(),

            DateTimePicker::make('due_at')
                ->label('Срок')
                ->seconds(false)
                ->default(now()->addHour())
                ->required(),

            Textarea::make('description')->label('Подробности')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('due_at')
                    ->label('Срок')
                    ->dateTime('d.m H:i')
                    ->sortable()
                    ->color(fn (Task $r) => $r->isOverdue() ? 'danger' : null)
                    ->weight(fn (Task $r) => $r->isOverdue() ? 'bold' : null),

                TextColumn::make('title')->label('Задача')->wrap(),

                TextColumn::make('assignee.name')->label('Кто')->placeholder('ничья'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),
            ])
            ->defaultSort('due_at')
            ->headerActions([
                CreateAction::make()
                    ->label('Новая задача')
                    ->mutateDataUsing(function (array $data): array {
                        $data['customer_id'] = $this->getOwnerRecord()->customer_id;
                        $data['created_by'] = Auth::id();

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Выполнена')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Task $r) => $r->status->isOpen())
                    ->schema([Textarea::make('result')->label('Что вышло')->rows(3)])
                    ->action(fn (Task $record, array $data) => $record->complete($data['result'] ?? null)),

                EditAction::make(),
            ])
            ->emptyStateHeading('Задач по заявке нет');
    }
}
