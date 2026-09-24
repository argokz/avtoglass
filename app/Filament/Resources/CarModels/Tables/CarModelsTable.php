<?php

namespace App\Filament\Resources\CarModels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CarModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Фото')
                    ->disk('public')
                    ->square(),

                TextColumn::make('brand.name')
                    ->label('Марка')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Модель')
                    ->searchable()
                    ->weight('bold')
                    /*
                     * Причина пометки идёт подписью под названием: без неё
                     * менеджер видит «4RUNNER а» и не понимает, что тут не так
                     * и что от него хотят.
                     */
                    ->description(fn ($record) => $record->review_note)
                    ->color(fn ($record) => $record->needs_review ? 'warning' : null),

                TextColumn::make('name_ru')
                    ->label('По-русски')
                    ->placeholder('не задано')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('products_count')
                    ->label('Товаров')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('fitments_count')
                    ->label('Подборов')
                    ->counts('fitments')
                    ->sortable(),

                IconColumn::make('is_popular')
                    ->label('Популярная')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('needs_review')
                    ->label('На проверке')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn ($record) => $record->review_note),
            ])
            ->filters([
                SelectFilter::make('car_brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('needs_review')
                    ->label('Только на проверке')
                    ->query(fn (Builder $query) => $query->where('needs_review', true))
                    ->default(false),

                TernaryFilter::make('is_popular')
                    ->label('Популярная'),

                Filter::make('без_товаров')
                    ->label('Без товаров')
                    ->query(fn (Builder $query) => $query->where('products_count', 0)),

                Filter::make('без_фото')
                    ->label('Без фото')
                    ->query(fn (Builder $query) => $query->where(function (Builder $inner) {
                        $inner->whereNull('image_path')
                            ->orWhere('image_path', 'like', '%.svg');
                    })),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->label('Открыть'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
