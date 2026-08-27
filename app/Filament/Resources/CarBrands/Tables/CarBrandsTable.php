<?php

namespace App\Filament\Resources\CarBrands\Tables;

use App\Models\CarBrand;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Марки. Колонок в таблице ровно столько, сколько нужно, чтобы понять,
 * что с маркой не так: нет логотипа, нет моделей, не вынесена в популярные.
 * Служебные поля — счётчик моделей и GUID из 1С — правятся не руками.
 */
class CarBrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo_path')
                    ->label('Логотип')
                    ->disk('public')
                    ->square(),

                TextColumn::make('name')
                    ->label('Марка')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (CarBrand $record) => $record->name_ru),

                TextColumn::make('models_count')
                    ->label('Моделей')
                    ->numeric()
                    ->sortable()
                    // Марка без моделей в каталог не попадает — это видно сразу
                    ->color(fn (int $state) => $state > 0 ? null : 'danger'),

                IconColumn::make('is_popular')
                    ->label('На главной')
                    ->boolean(),

                TextColumn::make('sort')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_popular')->label('На главной'),

                TernaryFilter::make('logo_path')
                    ->label('С логотипом')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('logo_path'),
                        false: fn ($query) => $query->whereNull('logo_path'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->label('Открыть'),
            ]);
    }
}
