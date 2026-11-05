<?php

namespace App\Filament\Resources\RiskBlocklist\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RiskBlocklistForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Что блокируем')
                ->options(['phone' => 'Телефон', 'ip' => 'IP-адрес'])
                ->required(),

            TextInput::make('value')
                ->label('Номер или адрес')
                ->required()
                ->helperText('Хранится хэш: сам номер в базе стоп-листа не оседает.'),

            TextInput::make('reason')
                ->label('Причина')
                ->maxLength(190),
        ]);
    }
}
