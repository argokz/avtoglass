<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\PaymentOptions;
use App\Models\Order;
use App\Models\ServicePoint;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

/** CRM input adapter; eligibility, tariffs and reservation remain domain operations. */
final class ProposalForm
{
    public static function receiving(Order $order): array
    {
        $draft = OrderDraft::query()->findOrFail($order->purchase_draft_id);
        $receiving = $draft->fulfillment;
        $point = ServicePoint::query()->find($receiving['service_point_id'] ?? null);
        $zone = $point?->timezone ?? config('app.timezone');
        $slot = fn (string $key) => empty($receiving[$key]) ? null : CarbonImmutable::parse($receiving[$key])->setTimezone($zone)->format('Y-m-d H:i:s');

        return [
            Section::make('Получение предложения')->description('Тариф, доступность пункта и время проверяются при расчёте. До принятия клиентом заказ не изменяется.')
                ->schema([
                    Select::make('fulfillment.type')->label('Получение')->options([
                        'pickup' => 'Самовывоз', 'delivery' => 'Доставка', 'installation' => 'Установка в сервисе',
                    ])->default($receiving['type'] ?? 'pickup')->required()->live(),
                    TextInput::make('fulfillment.city')->label('Город')->default($receiving['city'] ?? null)->required()->maxLength(120),
                    TextInput::make('fulfillment.address')->label('Адрес доставки')->default($receiving['address'] ?? null)->maxLength(500)
                        ->visible(fn (Get $get) => $get('fulfillment.type') === 'delivery')->required(fn (Get $get) => $get('fulfillment.type') === 'delivery'),
                    Select::make('fulfillment.service_point_id')->label('Пункт получения')->options(fn () => ServicePoint::query()->where('is_active', true)->get()->mapWithKeys(fn ($row) => [$row->id => $row->name.' · '.$row->city]))
                        ->default($receiving['service_point_id'] ?? null)->live()->visible(fn (Get $get) => $get('fulfillment.type') !== 'delivery')
                        ->required(fn (Get $get) => $get('fulfillment.type') !== 'delivery'),
                    DateTimePicker::make('fulfillment.slot_start')->label('Начало установки')->seconds(false)->native(false)->default($slot('slot_start'))
                        ->helperText('Местное время выбранного сервиса. Интервал должен учитывать длительность работ и буфер.')
                        ->visible(fn (Get $get) => $get('fulfillment.type') === 'installation')->required(fn (Get $get) => $get('fulfillment.type') === 'installation'),
                    DateTimePicker::make('fulfillment.slot_end')->label('Конец интервала')->seconds(false)->native(false)->default($slot('slot_end'))
                        ->visible(fn (Get $get) => $get('fulfillment.type') === 'installation')->required(fn (Get $get) => $get('fulfillment.type') === 'installation'),
                ])->columns(2),
            Select::make('payment_method')->label('Оплата предложения')
                ->options(PaymentOptions::available(BuyerContext::review(Auth::user(), $order->customer_id)))
                ->default($draft->payment_method ?? 'cash')->required(),
        ];
    }

    public static function changes(array $data): array
    {
        $changes = ['items' => array_values($data['items'])];
        foreach ($changes['items'] as &$item) {
            $item['offer_id'] = filled($item['offer_id'] ?? null) ? (int) $item['offer_id'] : null;
            $item['quantity'] = (int) $item['quantity'];
            $item['article_confirmed'] = (bool) ($item['article_confirmed'] ?? false);
            $item['manufacturer_confirmed'] = (bool) ($item['manufacturer_confirmed'] ?? false);
        }
        unset($item);
        $receiving = $data['fulfillment'];
        $type = $receiving['type'];
        $receiving['address'] = $type === 'delivery' ? ($receiving['address'] ?? null) : null;
        $receiving['service_point_id'] = $type === 'delivery' || blank($receiving['service_point_id'] ?? null) ? null : (int) $receiving['service_point_id'];
        $point = ServicePoint::query()->find($receiving['service_point_id']);
        foreach (['slot_start', 'slot_end'] as $field) {
            $receiving[$field] = $type === 'installation' && filled($receiving[$field] ?? null)
                ? CarbonImmutable::parse($receiving[$field], $point?->timezone ?? config('app.timezone'))->toIso8601String() : null;
        }

        return [...$changes, 'fulfillment' => $receiving, 'payment_method' => $data['payment_method']];
    }
}
