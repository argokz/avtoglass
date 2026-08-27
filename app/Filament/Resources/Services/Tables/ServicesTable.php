<?php

namespace App\Filament\Resources\Services\Tables;

use App\Enums\GlassType;
use App\Models\Service;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Работа')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (Service $record) => $record->description),

                // Цену правят прямо в списке: ради одной цифры открывать
                // карточку незачем, а меняются они часто.
                TextInputColumn::make('price')
                    ->label('Цена, ₸')
                    ->type('number')
                    ->rules(['numeric', 'min:0']),

                TextColumn::make('glass_type')
                    ->label('К стеклу')
                    ->badge()
                    ->placeholder('к любому')
                    ->formatStateUsing(fn (GlassType $state) => $state->label()),

                TextColumn::make('duration_minutes')
                    ->label('Минут')
                    ->numeric(),

                IconColumn::make('is_default')
                    ->label('На витрине')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Делаем')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Делаем'),
                TernaryFilter::make('is_default')->label('На витрине'),
            ])
            ->defaultSort('sort')
            ->recordActions([
                EditAction::make()->label('Открыть'),
            ])
            ->emptyStateHeading('Работы не заведены')
            ->emptyStateDescription('Пока их нет, покупатель видит цену стекла без установки.');
    }
}
