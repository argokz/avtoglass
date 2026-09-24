<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\WholesaleStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Контакты')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Имя')->required(),

                    TextInput::make('phone')
                        ->label('Телефон')
                        ->required()
                        ->tel()
                        ->helperText('Хранится только цифрами — так один человек не заводится дважды'),

                    TextInput::make('email')->label('Почта')->email(),
                ]),

            Section::make('Доставка')
                ->description('Подставляется в следующую заявку с сайта')
                ->columns(2)
                ->schema([
                    TextInput::make('default_city')->label('Город')->maxLength(64),
                    TextInput::make('default_address')->label('Адрес')->columnSpanFull(),
                    TextInput::make('preferred_fulfillment')
                        ->label('Предпочтительный способ')
                        ->helperText('installation / delivery / visit / pickup')
                        ->maxLength(32),
                ]),

            Section::make('Автомобиль')
                ->description('Заполняется со временем — при повторном обращении не придётся спрашивать заново')
                ->columns(3)
                ->schema([
                    Select::make('car_model_id')
                        ->label('Модель')
                        ->relationship('carModel', 'name')
                        ->searchable()
                        ->preload(),

                    TextInput::make('car_plate')->label('Госномер'),
                    TextInput::make('vin')->label('VIN')->maxLength(24),
                ]),

            Section::make('Опт')
                ->description('Закуп с витрины не показываем. Оптовая цена = розница минус скидка, но не ниже закупа.')
                ->columns(2)
                ->schema([
                    Select::make('wholesale_status')
                        ->label('Статус')
                        ->options(WholesaleStatus::options())
                        ->default(WholesaleStatus::Retail->value)
                        ->required(),

                    TextInput::make('wholesale_discount_percent')
                        ->label('Скидка, %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(40)
                        ->helperText('0 — те же цифры, что у розницы, но в кабинете интерфейс опта'),

                    TextInput::make('company_name')
                        ->label('Организация')
                        ->maxLength(190),

                    TextInput::make('bin')
                        ->label('БИН / ИИН')
                        ->maxLength(12)
                        ->helperText('12 цифр'),
                ]),

            Section::make('Заметки')
                ->schema([
                    Textarea::make('note')->hiddenLabel()->rows(3),
                ]),
        ]);
    }
}
