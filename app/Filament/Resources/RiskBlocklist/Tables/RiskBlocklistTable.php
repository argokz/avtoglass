<?php

namespace App\Filament\Resources\RiskBlocklist\Tables;

use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RiskBlocklistTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'phone' ? 'Телефон' : 'IP-адрес')
                    ->color(fn (string $state) => $state === 'phone' ? 'warning' : 'info'),

                TextColumn::make('value')
                    ->label('Хэш')
                    ->limit(16)
                    ->tooltip('Хранится только хэш: сам номер или адрес не восстановить')
                    ->copyable(),

                TextColumn::make('reason')
                    ->label('Причина')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Добавлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([DeleteAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Стоп-лист пуст');
    }
}
