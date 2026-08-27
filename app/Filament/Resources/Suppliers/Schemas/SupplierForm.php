<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Карточка поставщика. Для покупателя это марка стекла: в фильтре витрины
 * стоят именно эти названия, а рядом с ценой — страна и класс.
 */
class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Марка стекла')
                    ->required()
                    ->helperText('Так поставщик виден покупателю в фильтре'),

                TextInput::make('slug')
                    ->label('Адрес')
                    ->required(),

                Select::make('tier')
                    ->label('Класс')
                    ->required()
                    ->default('standard')
                    ->options([
                        'economy' => 'Эконом',
                        'standard' => 'Стандарт',
                        'premium' => 'Премиум',
                        'oem' => 'Оригинал / OEM',
                    ]),

                /*
                 * Страна — второй вопрос покупателя после цены: китайское
                 * стекло и европейское на одну машину различаются втрое.
                 * В прайсах её нет, поэтому заполняется здесь. Бесспорные
                 * заводы проставляет `catalog:enrich`, спорные — человек.
                 */
                TextInput::make('country')
                    ->label('Страна производства')
                    ->placeholder('Китай, Россия, Япония…')
                    ->helperText('Показывается покупателю рядом с ценой'),

                TextInput::make('warranty_months')
                    ->label('Гарантия, мес.')
                    ->required()
                    ->numeric()
                    ->default(12),

                TextInput::make('default_lead_days')
                    ->label('Срок поставки, дней')
                    ->required()
                    ->numeric()
                    ->default(0),

                TextInput::make('markup_percent')
                    ->label('Наценка, %')
                    ->required()
                    ->numeric()
                    ->default(30)
                    ->helperText('Из неё считается розничная цена при сборке каталога'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->required()
                    ->numeric()
                    ->default(500),

                Toggle::make('is_active')
                    ->label('Возим'),

                Textarea::make('description')
                    ->label('Заметка')
                    ->columnSpanFull(),

                // external_id приходит из 1С и руками не правится
            ]);
    }
}
