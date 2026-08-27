<?php

namespace App\Filament\Resources\CarModelAliases\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarModelAliasForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Соответствие')
                ->description('Как написано в прайсе — и как это называется на самом деле')
                ->columns(2)
                ->schema([
                    Select::make('car_brand_id')
                        ->label('Марка')
                        ->relationship('brand', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('Псевдоним действует только внутри своей марки'),

                    TextInput::make('alias')
                        ->label('Как в прайсе')
                        ->required()
                        ->helperText('Например: W140, S-Class'),

                    TextInput::make('model_name')
                        ->label('Каноническое имя модели')
                        ->required()
                        ->helperText('Например: S-Class'),

                    TextInput::make('generation')
                        ->label('Поколение')
                        ->helperText('Если в сыром имени было поколение — например W140'),

                    TextInput::make('comment')
                        ->label('Комментарий')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
