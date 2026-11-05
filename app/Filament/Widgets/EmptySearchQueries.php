<?php

namespace App\Filament\Widgets;

use App\Models\SearchQuery;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Что искали и не нашли.
 *
 * Запрос с нулём результатов — это упущенная продажа и готовый пункт
 * плана пополнения каталога. Показываем самые частые за месяц.
 */
class EmptySearchQueries extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (Auth::user()?->role->canManageCatalog() ?? false)
            && Schema::hasTable('search_queries');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Поиск без результата за 30 дней')
            ->query(
                SearchQuery::query()
                    ->select(['query', DB::raw('COUNT(*) as total'), DB::raw('MAX(created_at) as last_at')])
                    ->where('results_count', 0)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('query')
                    ->orderByDesc('total')
                    ->limit(20)
            )
            ->columns([
                TextColumn::make('query')->label('Запрос')->weight('bold'),
                TextColumn::make('total')->label('Раз')->badge()->color('danger'),
                TextColumn::make('last_at')->label('Последний')->dateTime('d.m.Y H:i'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Пустых запросов нет — каталог закрывает спрос');
    }
}
