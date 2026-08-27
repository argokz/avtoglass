<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('tier')
                    ->required()
                    ->default('standard'),
                TextInput::make('country'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('warranty_months')
                    ->required()
                    ->numeric()
                    ->default(12),
                TextInput::make('default_lead_days')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('markup_percent')
                    ->required()
                    ->numeric()
                    ->default(30),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(500),
                TextInput::make('external_id'),
            ]);
    }
}
