<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FitmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'fitments';

    protected static ?string $title = 'Проверенная применимость';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('car_model_id')->label('Модель')->relationship('carModel', 'name')->searchable()->required(),
            Select::make('car_generation_id')->label('Поколение')->relationship('generation', 'name')->searchable(),
            TextInput::make('year_from')->label('С года')->integer()->minValue(1900)->maxValue(now()->year + 2)->required(),
            TextInput::make('year_to')->label('По год')->integer()->minValue(1900)->maxValue(now()->year + 2)->gte('year_from'),
            TextInput::make('body_type')->label('Кузов')->maxLength(80), TextInput::make('doors')->label('Дверей')->maxLength(8),
            TextInput::make('note')->label('Особые условия (требуют согласования)')->maxLength(500),
            Toggle::make('is_verified')->label('Применимость проверена')->live(),
            TextInput::make('verification_source')->label('Источник проверки')->maxLength(190)->required(fn ($get) => (bool) $get('is_verified')),
            DateTimePicker::make('verified_at')->label('Дата проверки')->default(now())->beforeOrEqual('now')->required(fn ($get) => (bool) $get('is_verified')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('carModel.name')->label('Модель'), TextColumn::make('year_from')->label('С'), TextColumn::make('year_to')->label('По'),
            IconColumn::make('is_verified')->label('Проверено')->boolean(), TextColumn::make('verification_source')->label('Источник')])
            ->headerActions([CreateAction::make()])->recordActions([EditAction::make()]);
    }
}
