<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\WholesaleStatus;
use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('orders'))
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('phone')
                    ->label('Телефон')
                    // Шифротекст подстрокой не ищется — только точный номер через хэш.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $hash = Customer::phoneHash($search);

                        return $hash !== null
                            ? $query->where('phone_hash', $hash)
                            : $query->whereRaw('1 = 0');
                    })
                    ->url(fn (Customer $r) => 'tel:'.$r->phone)
                    ->copyable(),

                TextColumn::make('wholesale_status')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof WholesaleStatus
                        ? $state->label()
                        : WholesaleStatus::tryFrom((string) $state)?->label())
                    ->color(fn ($state) => match ($state instanceof WholesaleStatus ? $state : WholesaleStatus::tryFrom((string) $state)) {
                        WholesaleStatus::Wholesale => 'success',
                        WholesaleStatus::Pending => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('company_name')
                    ->label('Организация')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('wholesale_discount_percent')
                    ->label('Скидка')
                    ->suffix('%')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('carModel.name')
                    ->label('Автомобиль')
                    ->placeholder('—')
                    ->description(fn (Customer $r) => $r->car_plate)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('orders_count')
                    ->label('Заявок')
                    ->badge()
                    // Повторное обращение — повод разговаривать иначе,
                    // поэтому такие клиенты подсвечены.
                    ->color(fn (int $state) => $state > 1 ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Первое обращение')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('wholesale_status')
                    ->label('Тип')
                    ->options(WholesaleStatus::options()),
                Filter::make('repeat')
                    ->label('Обращались повторно')
                    ->query(fn (Builder $query) => $query->has('orders', '>', 1)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Клиентов пока нет');
    }
}
