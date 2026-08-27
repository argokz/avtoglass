<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
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
                    ->searchable()
                    ->url(fn (Customer $r) => 'tel:'.$r->phone)
                    ->copyable(),

                TextColumn::make('carModel.name')
                    ->label('Автомобиль')
                    ->placeholder('—')
                    ->description(fn (Customer $r) => $r->car_plate),

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
                Filter::make('repeat')
                    ->label('Обращались повторно')
                    ->query(fn (Builder $query) => $query->has('orders', '>', 1)),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Клиентов пока нет');
    }
}
