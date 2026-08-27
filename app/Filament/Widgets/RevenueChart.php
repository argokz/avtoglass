<?php

namespace App\Filament\Widgets;

use App\Analytics\SalesReport;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Выручка по дням за месяц. Дни без продаж рисуются нулями, а не
 * пропускаются: иначе график склеивает разрывы и показывает ровный рост
 * там, где неделю было пусто.
 */
class RevenueChart extends ChartWidget
{
    protected ?string $heading = 'Выручка по дням';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $byDay = SalesReport::forDays(30)->revenueByDay();

        return [
            'datasets' => [[
                'label' => 'Получено, ₸',
                'data' => $byDay->values()->all(),
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => $byDay->keys()
                ->map(fn (string $d) => Carbon::parse($d)->format('d.m'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
