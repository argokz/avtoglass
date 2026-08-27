<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\GlassType;
use App\Models\Product;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Рабочий стол контент-менеджера.
 *
 * Колонок у товара больше тридцати, но в таблицу вынесены только те, по которым
 * принимают решение: что это, почём, сколько поставщиков и видно ли покупателю.
 * Остальное — в карточке редактирования.
 */
class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('offers'))
            ->columns([
                // Видно сразу, у каких позиций фото уже есть: снимают их
                // выборочно, и без колонки непонятно, что осталось.
                ImageColumn::make('image_path')
                    ->label('Фото')
                    ->disk('public')
                    ->square()
                    ->defaultImageUrl(null),

                TextColumn::make('name')
                    ->label('Наименование')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (Product $r) => $r->eurocode ? "Еврокод: {$r->eurocode}" : null),

                TextColumn::make('glass_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (?GlassType $state) => $state?->label())
                    ->color(fn (?GlassType $state) => match ($state) {
                        GlassType::Windshield => 'info',
                        GlassType::Rear => 'success',
                        GlassType::Side => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('price_min')
                    ->label('Цена')
                    ->money('KZT', locale: 'ru')
                    ->sortable()
                    ->placeholder('по запросу'),

                TextColumn::make('offers_count')
                    ->label('Предл.')
                    ->badge()
                    ->color(fn (int $state) => $state > 1 ? 'success' : 'gray')
                    ->sortable(),

                IconColumn::make('camera')
                    ->label('ADAS')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('views')
                    ->label('Просмотры')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Публикация переключается прямо в списке: это самое частое действие
                ToggleColumn::make('is_published')->label('В витрине'),

                IconColumn::make('needs_review')
                    ->label('Проверить')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->filters([
                SelectFilter::make('glass_type')
                    ->label('Тип стекла')
                    ->options(collect(GlassType::cases())
                        ->mapWithKeys(fn (GlassType $t) => [$t->value => $t->label()])),

                TernaryFilter::make('is_published')->label('В витрине'),

                TernaryFilter::make('needs_review')->label('На проверке'),

                // Разбор очереди начинается отсюда: карточки без цены нельзя публиковать
                Filter::make('no_price')
                    ->label('Без цены')
                    ->query(fn (Builder $query) => $query->whereNull('price_min')->orWhere('price_min', '<=', 0)),

                Filter::make('multi_offer')
                    ->label('Несколько поставщиков')
                    ->query(fn (Builder $query) => $query->has('offers', '>', 1)),
            ])
            ->defaultSort('views', 'desc')
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publish')
                        ->label('Опубликовать')
                        ->icon('heroicon-o-eye')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update([
                            'is_published' => true,
                            'needs_review' => false,
                        ])),

                    BulkAction::make('unpublish')
                        ->label('Снять с витрины')
                        ->icon('heroicon-o-eye-slash')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['is_published' => false])),
                ]),
            ]);
    }
}
