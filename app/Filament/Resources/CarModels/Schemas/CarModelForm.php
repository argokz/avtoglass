<?php

namespace App\Filament\Resources\CarModels\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CarModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('car_brand_id')
                    ->label('Марка')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('name')
                    ->label('Модель')
                    ->required()
                    ->helperText('Так модель называется в каталоге и в подборе.'),

                TextInput::make('name_ru')
                    ->label('По-русски')
                    ->helperText('Необязательно. Нужно там, где покупатель ищет русским словом.'),

                /*
                 * Адрес модели уже разошёлся по ссылкам и поисковой выдаче.
                 * Менять его можно, но осознанно, поэтому поле не в первых
                 * рядах и с предупреждением, а не просто «slug, required».
                 */
                TextInput::make('slug')
                    ->label('Адрес страницы')
                    ->required()
                    ->helperText('Меняйте только при явной необходимости: старые ссылки на эту модель перестанут работать.'),

                FileUpload::make('image_path')
                    ->label('Фото автомобиля')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('models')
                    ->maxSize(4096)
                    ->columnSpanFull()
                    ->helperText('JPG или PNG, до 4 МБ. Вид спереди или сбоку, без силуэта-заглушки.'),

                TextInput::make('products_count')
                    ->label('Товаров')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Считается сборкой каталога, руками не задаётся.'),

                Toggle::make('is_popular')
                    ->label('Популярная')
                    ->helperText('Попадает в короткий список на главной и в подборе.'),

                Toggle::make('needs_review')
                    ->label('На проверке')
                    ->live()
                    ->helperText('Снимите, когда название приведено в порядок.'),

                Textarea::make('review_note')
                    ->label('Что не так с названием')
                    ->rows(2)
                    ->visible(fn ($get) => (bool) $get('needs_review'))
                    ->helperText('Заполняется командой catalog:flag-models, но можно дописать своё.'),
            ]);
    }
}
