<?php

namespace App\Filament\Resources\CarModelAliases\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CarModelAliasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.name')
                    ->label('Марка')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('alias')
                    ->label('Как в прайсе')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('model_name')
                    ->label('Каноническое имя')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('generation')
                    ->label('Поколение')
                    ->placeholder('—'),

                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('car_brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('Псевдонимов пока нет')
            ->emptyStateDescription(
                'Заводите их для имён, которые не разобрать правилами: '
                .'«W140, S-Class» или «LANDCR». Псевдоним применится при следующей сборке каталога.'
            );
    }
}
