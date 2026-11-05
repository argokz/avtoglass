<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Offer;
use App\Models\ServicePoint;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
            TextInput::make('lead_days')->label('Срок поставки, дней')->numeric()->minValue(0)
                ->helperText('Пусто — берётся срок поставщика. Заполняйте, только если у этого стекла срок другой.'),
            Toggle::make('is_active')->label('Активно'),
            TextInput::make('verification_source')->label('Источник проверки цены и остатка')->maxLength(190)->required(fn ($get) => (bool) $get('auto_checkout_enabled')),
            DateTimePicker::make('price_valid_until')->label('Цена действует до')->after('now')->required(fn ($get) => (bool) $get('auto_checkout_enabled')),
            DateTimePicker::make('stock_verified_at')->label('Остаток проверен')->beforeOrEqual('now')->required(fn ($get) => (bool) $get('auto_checkout_enabled')),
            Toggle::make('auto_checkout_enabled')->label('Разрешить автопокупку')->live()->helperText('Только после фактической проверки. Применимость и получение проверяются отдельно.'),
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

                // Цена, остаток и активность правятся на месте — это самые
                // частые действия, и ради них не стоит открывать форму.
                // После правки пересчитываем «от … ₸» в карточке товара.
                TextInputColumn::make('price_retail')
                    ->label('Розница, ₸')
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0', 'max:100000000'])
                    ->afterStateUpdated(fn (Offer $record) => $record->product?->refreshPricing())
                    ->sortable(),

                TextInputColumn::make('stock_qty')
                    ->label('Остаток')
                    ->type('number')
                    ->rules(['integer', 'min:0'])
                    ->afterStateUpdated(fn (Offer $record) => $record->product?->refreshPricing()),

                ToggleColumn::make('is_active')->label('Активно'),

                TextColumn::make('article')->label('Артикул')->placeholder('—')->toggleable(),
                TextColumn::make('price_valid_until')->label('Цена до')->dateTime()->toggleable(),
                TextColumn::make('stock_verified_at')->label('Проверка остатка')->dateTime()->toggleable(),
                TextColumn::make('auto_checkout_enabled')->label('Автопокупка')->formatStateUsing(fn ($state) => $state ? 'Разрешена' : 'Согласование'),

                TextColumn::make('warranty_months')
                    ->label('Гарантия')
                    ->suffix(' мес.')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('price_retail')
            ->recordActions([
                EditAction::make(),
                Action::make('fulfillment')->label('Получение')
                    ->schema([
                        Repeater::make('points')->label('Разрешённые пункты')
                            ->default(fn (Offer $record) => DB::table('offer_fulfillment_points')->where('offer_id', $record->id)->get()->map(fn ($row) => ['service_point_id' => $row->service_point_id, 'pickup_enabled' => $row->pickup_enabled, 'installation_enabled' => $row->installation_enabled])->all())
                            ->schema([
                                Select::make('service_point_id')->label('Пункт')->options(fn () => ServicePoint::query()->where('is_active', true)->get()->mapWithKeys(fn ($point) => [$point->id => $point->city.' · '.$point->name]))->required()->distinct(),
                                Toggle::make('pickup_enabled')->label('Самовывоз'), Toggle::make('installation_enabled')->label('Установка'),
                            ])->maxItems(100),
                    ])->action(function (Offer $record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            Offer::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                            Validator::make($data, ['points' => ['array', 'max:100'], 'points.*.service_point_id' => ['required', 'integer', 'distinct', 'exists:service_points,id']])->validate();
                            DB::table('offer_fulfillment_points')->where('offer_id', $record->id)->delete();
                            foreach ($data['points'] ?? [] as $point) {
                                DB::table('offer_fulfillment_points')->insert(['offer_id' => $record->id, 'service_point_id' => (int) $point['service_point_id'],
                                    'pickup_enabled' => (bool) ($point['pickup_enabled'] ?? false), 'installation_enabled' => (bool) ($point['installation_enabled'] ?? false)]);
                            }
                        });
                    }),
            ])
            ->emptyStateHeading('Предложений нет');
    }
}
