<?php

namespace App\Filament\Resources\CarBrands\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CarBrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Марка')
                    ->required(),

                TextInput::make('name_ru')
                    ->label('По-русски')
                    ->helperText('Чтобы марку находили кириллицей: «Тойота»'),

                TextInput::make('slug')
                    ->label('Адрес в каталоге')
                    ->required()
                    ->helperText('Часть ссылки: /catalog/toyota'),

                /*
                 * Логотип — единственное, что отличает список марок от списка
                 * слов. Покупатель ищет свою машину глазами, а не читает
                 * алфавитный указатель.
                 */
                FileUpload::make('logo_path')
                    ->label('Логотип')
                    ->image()
                    ->disk('public')
                    ->directory('brands')
                    ->maxSize(512)
                    ->helperText('PNG или SVG на прозрачном фоне, до 512 КБ'),

                Toggle::make('is_popular')
                    ->label('Показывать в популярных')
                    ->helperText('Такие марки выносятся на главную'),

                TextInput::make('sort')
                    ->label('Порядок')
                    ->numeric()
                    ->default(500)
                    ->helperText('Меньше — выше в списке'),

                // models_count считает сборка каталога, external_id приходит
                // из 1С — оба поля руками не правят: следующая выгрузка
                // всё равно перезапишет значение.
            ]);
    }
}
