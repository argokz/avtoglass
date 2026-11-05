<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->columns(3)
                ->schema([
                    TextInput::make('number')->label('Номер')->disabled(),

                    Select::make('status')
                        ->label('Статус')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->options(OrderStatus::options())
                        ->required(),

                    Select::make('manager_id')
                        ->label('Менеджер')
                        ->relationship('manager', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder('не назначен'),

                    Select::make('source')
                        ->label('Источник')
                        ->options(OrderSource::options())
                        ->required(),

                    Select::make('fulfillment')
                        ->label('Что нужно клиенту')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->options(FulfillmentType::options())
                        ->live(),

                    Select::make('payment_method')
                        ->label('Оплата')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->options(PaymentMethod::options()),

                    DateTimePicker::make('scheduled_at')
                        ->label('Назначено на')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->seconds(false)
                        ->helperText('Время доставки или замены'),

                    TextInput::make('city')
                        ->label('Город')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->maxLength(64),

                    TextInput::make('address')
                        ->label('Адрес')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->columnSpanFull()
                        ->visible(fn (callable $get) => in_array($get('fulfillment'), [
                            FulfillmentType::Delivery->value,
                            FulfillmentType::Visit->value,
                        ], true)),
                ]),

            Section::make('Клиент')
                ->columns(2)
                ->schema([
                    Select::make('customer_id')
                        ->label('Клиент')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->relationship('customer', 'name')
                        // Телефон зашифрован: поиск по нему идёт через слепой индекс.
                        ->getSearchResultsUsing(fn (string $search): array => Customer::searchForSelect($search))
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::find($value)?->name)
                        ->searchable()
                        ->preload()
                        ->required(),
                ]),

            Section::make('Позиции')
                ->description('Цены — снимок на момент заказа: пересборка каталога их не изменит')
                ->schema([
                    Repeater::make('items')
                        ->disabled(fn (?Order $record) => filled($record?->purchase_draft_id))
                        ->hiddenLabel()
                        ->relationship()
                        ->columns(4)
                        ->defaultItems(0)
                        ->addActionLabel('Добавить позицию')
                        ->schema([
                            TextInput::make('name')
                                ->label('Наименование')
                                ->required()
                                ->columnSpan(2),

                            TextInput::make('price')
                                ->label('Цена, ₸')
                                ->numeric(),

                            TextInput::make('quantity')
                                ->label('Кол-во')
                                ->numeric()
                                ->default(1)
                                ->minValue(1),
                        ]),
                ]),

            Section::make('Комментарии')
                ->columns(2)
                ->schema([
                    Textarea::make('comment')
                        ->label('От клиента')
                        ->rows(4)
                        ->disabled()
                        ->helperText('То, что человек написал в форме — не редактируем'),

                    Textarea::make('manager_note')
                        ->label('Заметка менеджера')
                        ->rows(4),

                    Textarea::make('cancel_reason')
                        ->label('Причина отмены')
                        ->rows(2)
                        ->columnSpanFull()
                        ->visible(fn (?Order $record) => $record?->status === OrderStatus::Cancelled),
                ]),
        ]);
    }
}
