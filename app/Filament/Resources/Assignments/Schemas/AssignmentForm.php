<?php

namespace App\Filament\Resources\Assignments\Schemas;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Наряд')
                ->columns(3)
                ->schema([
                    Select::make('order_id')
                        ->label('Заявка')
                        ->relationship('order', 'number')
                        ->searchable()
                        ->preload()
                        ->required(),

                    Select::make('type')
                        ->label('Работа')
                        ->options(AssignmentType::options())
                        ->required()
                        ->live(),

                    Select::make('status')
                        ->label('Статус')
                        ->options(AssignmentStatus::options())
                        ->required(),

                    DateTimePicker::make('scheduled_at')
                        ->label('Начало')
                        ->seconds(false)
                        ->required(),

                    TextInput::make('duration_minutes')
                        ->label('Длительность, мин')
                        ->numeric()
                        ->required()
                        ->default(120),

                    TextInput::make('sequence')
                        ->label('Порядок в маршруте')
                        ->numeric()
                        ->helperText('Для курьера — очерёдность за день'),
                ]),

            Section::make('Где и кто')
                ->columns(2)
                ->schema([
                    Select::make('service_point_id')
                        ->label('Сервис')
                        ->relationship('servicePoint', 'name')
                        ->preload()
                        // установка привязана к посту, доставка — к адресу клиента
                        ->visible(fn (callable $get) => $get('type') === AssignmentType::Installation->value),

                    TextInput::make('address')
                        ->label('Адрес доставки')
                        ->visible(fn (callable $get) => $get('type') === AssignmentType::Delivery->value),

                    Select::make('assignee_id')
                        ->label('Исполнитель')
                        // Показываем только тех, кто такую работу выполняет:
                        // курьеру нечего делать в списке установщиков.
                        ->options(fn (callable $get) => User::query()
                            ->where('is_active', true)
                            ->when(
                                $get('type'),
                                fn ($query, $type) => $query->whereIn('role', [
                                    AssignmentType::from($type)->performedBy()->value,
                                    UserRole::Admin->value,
                                ])
                            )
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('не назначен'),
                ]),

            Section::make('Итог')
                ->columns(2)
                ->schema([
                    Textarea::make('note')->label('Заметка')->rows(3),

                    Textarea::make('failure_reason')
                        ->label('Почему не получилось')
                        ->rows(3)
                        ->visible(fn (callable $get) => $get('status') === AssignmentStatus::Failed->value),
                ]),
        ]);
    }
}
