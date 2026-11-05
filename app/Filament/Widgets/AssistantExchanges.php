<?php

namespace App\Filament\Widgets;

use App\Models\AiExchangeLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Последние вопросы помощнику и карточки, которые он показал.
 *
 * Сырой поиск и показанный список рядом: если в чат попали чужие машины,
 * это видно без разбора логов.
 */
class AssistantExchanges extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (Auth::user()?->role->canManageCatalog() ?? false)
            && Schema::hasTable('ai_exchange_logs');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Вопросы помощнику')
            ->query(AiExchangeLog::query()->latest())
            ->columns([
                TextColumn::make('created_at')->label('Когда')->dateTime('d.m.Y H:i')->width('8rem'),
                TextColumn::make('question')->label('Вопрос')->wrap()->limit(120),
                TextColumn::make('answer')->label('Ответ')->wrap()->limit(160)->placeholder('—'),
                TextColumn::make('shown_offers')
                    ->label('Показали')
                    ->getStateUsing(fn (AiExchangeLog $record): string => implode('; ', $record->shown_offers ?? []))
                    ->wrap()
                    ->placeholder('нет карточек'),
                TextColumn::make('raw_offers')
                    ->label('Поиск вернул')
                    ->getStateUsing(fn (AiExchangeLog $record): string => implode('; ', $record->raw_offers ?? []))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Статус')->badge(),
            ])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Помощнику ещё не задавали вопросов');
    }
}
