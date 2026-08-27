<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Offer;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Предложения поставщиков по карточке.
 *
 * Остаток правится прямо в списке: пока нет выгрузки из 1С, это единственный
 * способ сказать «две штуки лежат на складе», и делать ради каждой правки
 * два перехода в форму — значит, что складом просто не будут заниматься.
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    protected static ?string $title = 'Предложения и остатки';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('article')->label('Артикул'),
            TextInput::make('price_wholesale')->label('Закуп, ₸')->numeric(),
            TextInput::make('price_retail')->label('Розница, ₸')->numeric(),
            TextInput::make('stock_qty')->label('Остаток, шт')->numeric()->minValue(0),
            TextInput::make('lead_days')->label('Срок поставки, дней')->numeric(),
            Toggle::make('is_active')->label('Активно'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('article')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Поставщик')
                    ->description(fn (Offer $r) => $r->supplier?->tierLabel()),

                TextColumn::make('price_retail')
                    ->label('Розница')
                    ->money('KZT', locale: 'ru')
                    ->sortable(),

                // Остаток и активность правятся на месте — это самые частые
                // действия, и ради них не стоит открывать форму
                TextInputColumn::make('stock_qty')
                    ->label('Остаток')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->afterStateUpdated(fn (Offer $record) => $record->product?->refreshPricing()),

                ToggleColumn::make('is_active')->label('Активно'),

                TextColumn::make('article')->label('Артикул')->placeholder('—')->toggleable(),

                TextColumn::make('warranty_months')
                    ->label('Гарантия')
                    ->suffix(' мес.')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('price_retail')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Предложений нет');
    }
}
