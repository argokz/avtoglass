<?php

namespace App\Filament\Widgets;

use App\Analytics\SalesReport;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Деньги за месяц. Стоит ниже оперативных виджетов: это не то, на что
 * реагируют сейчас, а то, по чему судят о работе за период.
 */
class SalesStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $month = SalesReport::forMonth();
        $week = SalesReport::forDays(7);

        return [
            Stat::make('Выручка за месяц', $this->money($month->revenue()))
                ->description('получено на счёт, '.$month->paymentsCount().' оплат')
                ->color('success')
                ->chart($this->sparkline()),

            Stat::make('Средний чек', $this->money($month->averageCheck()))
                ->description('по оплаченным заказам')
                ->color('primary'),

            Stat::make('Заявок за месяц', (string) $month->ordersCount())
                ->description($month->cancelledCount().' отменено')
                ->color('gray'),

            Stat::make('Конверсия', $month->conversion().' %')
                ->description('заявки, дошедшие до сделки')
                ->color($month->conversion() >= 30 ? 'success' : 'warning'),
        ];
    }

    /** Мини-график за неделю прямо в плитке выручки. */
    private function sparkline(): array
    {
        return SalesReport::forDays(7)->revenueByDay()->values()->all();
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', ' ').' ₸';
    }
}
