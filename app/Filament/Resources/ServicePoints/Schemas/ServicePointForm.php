<?php

namespace App\Filament\Resources\ServicePoints\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ServicePointForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('address')
                    ->required(),
                TextInput::make('city')
                    ->label('Город')
                    ->helperText('Витрина показывает пост только в этом городе')
                    ->maxLength(64),
                TextInput::make('phone')
                    ->tel(),
                \Filament\Forms\Components\Select::make('timezone')->label('Часовой пояс')
                    ->options(array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()))->searchable()->required()->default('Asia/Qyzylorda'),
                TextInput::make('bays')
                    ->required()
                    ->integer()->minValue(1)->maxValue(50)
                    ->default(1),
                TimePicker::make('opens_at')
                    ->required(),
                TimePicker::make('closes_at')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
