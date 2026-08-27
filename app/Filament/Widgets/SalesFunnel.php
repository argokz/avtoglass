<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Воронка: сколько заявок стоит в каждом статусе.
 *
 * Показываем только живые стадии. «Выполнен» и «Отменён» в воронку не входят —
 * они не требуют действий, а за месяц накопятся тысячами и утопят то,
 * ради чего этот экран и открывают.
 */
class SalesFunnel extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $counts = Order::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $live = [
            OrderStatus::New,
            OrderStatus::InProgress,
            OrderStatus::Confirmed,
            OrderStatus::Scheduled,
        ];

        return array_map(
            fn (OrderStatus $status) => Stat::make(
                $status->label(),
                (string) ($counts[$status->value] ?? 0)
            )
                ->color($status->color())
                ->url(OrderResource::getUrl('index', [
                    'tableFilters' => ['status' => ['values' => [$status->value]]],
                ])),
            $live
        );
    }
}
