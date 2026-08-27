<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Марки стекла — так поставщик виден покупателю в фильтре витрины.
 *
 * Страна правится прямо в списке: заполнить её надо всего у нескольких строк,
 * и открывать ради одного слова карточку незачем. Пустая страна подсвечена —
 * покупатель выбирает по ней сразу после цены.
 */
class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('offers'))
            ->columns([
                TextColumn::make('name')
                    ->label('Марка стекла')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextInputColumn::make('country')
                    ->label('Страна')
                    ->placeholder('не указана')
                    ->rules(['max:60']),

                TextColumn::make('tier')
                    ->label('Класс')
                    ->badge()
                    ->formatStateUsing(fn (Supplier $record) => $record->tierLabel()),

                TextInputColumn::make('markup_percent')
                    ->label('Наценка, %')
                    ->type('number')
                    ->rules(['numeric', 'min:0']),

                TextColumn::make('offers_count')
                    ->label('Позиций')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('warranty_months')
                    ->label('Гарантия')
                    ->suffix(' мес.'),

                IconColumn::make('is_active')
                    ->label('Возим')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Возим'),

                TernaryFilter::make('country')
                    ->label('Со страной')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('country'),
                        false: fn ($query) => $query->whereNull('country'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->defaultSort('offers_count', 'desc')
            ->recordActions([
                EditAction::make()->label('Открыть'),
            ]);
    }
}
