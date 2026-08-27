<?php

namespace App\Filament\Resources\CarModels\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CarModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('car_brand_id')
                    ->required()
                    ->numeric(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('name_ru'),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('products_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_popular')
                    ->required(),
                TextInput::make('external_id'),
            ]);
    }
}
