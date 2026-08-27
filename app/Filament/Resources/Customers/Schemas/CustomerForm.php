<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Контакты')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Имя')->required(),

                    TextInput::make('phone')
                        ->label('Телефон')
                        ->required()
                        ->tel()
                        ->helperText('Хранится только цифрами — так один человек не заводится дважды'),

                    TextInput::make('email')->label('Почта')->email(),
                ]),

            Section::make('Автомобиль')
                ->description('Заполняется со временем — при повторном обращении не придётся спрашивать заново')
                ->columns(3)
                ->schema([
                    Select::make('car_model_id')
                        ->label('Модель')
                        ->relationship('carModel', 'name')
                        ->searchable()
                        ->preload(),

                    TextInput::make('car_plate')->label('Госномер'),
                    TextInput::make('vin')->label('VIN')->maxLength(24),
                ]),

            Section::make('Заметки')
                ->schema([
                    Textarea::make('note')->hiddenLabel()->rows(3),
                ]),
        ]);
    }
}
