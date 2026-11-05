<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Fitment;
use App\Models\Offer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

final class CatalogAutomationReadiness extends StatsOverviewWidget
{
    protected static ?int $sort = 7;

    public static function canView(): bool
    {
        return (auth()->user()?->role->canManageCatalog() ?? false) && Schema::hasTable('assistant_sessions');
    }

    protected function getStats(): array
    {
        $active = Offer::query()->where('is_active', true)->count();
        $fresh = Offer::query()->where('is_active', true)->where('auto_checkout_enabled', true)->where('price_valid_until', '>', now())
            ->where('stock_verified_at', '>=', now()->subHours((int) config('ordering.stock_freshness_hours', 24)))->where('stock_verified_at', '<=', now())->count();

        return [Stat::make('Цена и остаток подтверждены', $fresh.' / '.$active)->description('Разрешённые предложения; получение и применимость проверяются при расчёте')->color($fresh ? 'success' : 'warning'),
            Stat::make('Проверенная применимость', Fitment::query()->where('is_verified', true)->whereNotNull('year_from')->count())->description('Записи с известным началом диапазона лет')];
    }
}
