<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Попытки оплаты по заказу.
 *
 * Только чтение и намеренно: платёж — это факт, случившийся в банке, а не
 * запись, которую можно поправить. Именно на неё ссылаются, когда клиент
 * говорит «деньги списались, а заказа нет», и переписанной она бесполезна.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Оплаты';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('token')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Начата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Сумма')
                    ->money('KZT', locale: 'ru'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => $state->color())
                    ->description(fn (Payment $r) => $r->failure_reason),

                TextColumn::make('provider')->label('Касса')->badge()->color('gray'),

                TextColumn::make('external_id')
                    ->label('ID в банке')
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('paid_at')
                    ->label('Оплачен')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Онлайн-оплат по заявке не было');
    }
}
