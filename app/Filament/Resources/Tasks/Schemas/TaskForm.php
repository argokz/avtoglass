<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Задача')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label('Что сделать')
                        ->required()
                        ->columnSpanFull(),

                    DateTimePicker::make('due_at')
                        ->label('Срок')
                        ->seconds(false)
                        ->required()
                        // Срок обязателен намеренно: задача без срока
                        // не всплывёт ни в одном списке и потеряется.
                        ->helperText('Без срока задача не попадёт ни в «сегодня», ни в «просроченные»'),

                    Select::make('assigned_to')
                        ->label('Исполнитель')
                        ->options(fn () => User::query()
                            ->where('is_active', true)
                            ->whereIn('role', [UserRole::Manager->value, UserRole::Admin->value])
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('ничья'),

                    Textarea::make('description')->label('Подробности')->rows(3)->columnSpanFull(),
                ]),

            Section::make('Связи')
                ->columns(2)
                ->schema([
                    Select::make('customer_id')
                        ->label('Клиент')
                        ->relationship('customer', 'name')
                        // Телефон зашифрован: поиск по нему идёт через слепой индекс.
                        ->getSearchResultsUsing(fn (string $search): array => Customer::searchForSelect($search))
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::find($value)?->name)
                        ->searchable()
                        ->preload(),

                    Select::make('order_id')
                        ->label('Заявка')
                        ->relationship('order', 'number')
                        ->searchable()
                        ->preload(),
                ]),

            Section::make('Итог')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label('Статус')
                        ->options(TaskStatus::options())
                        ->required(),

                    Textarea::make('result')->label('Результат')->rows(3),
                ]),
        ]);
    }
}
