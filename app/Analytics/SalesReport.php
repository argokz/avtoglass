<?php

namespace App\Analytics;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Отчёты по продажам.
 *
 * Главное решение здесь — что считать выручкой. Считаем только фактически
 * полученные деньги, то есть успешные платежи. Сумма заказов выручкой не
 * является: заявка в статусе «Новая» — это намерение, а не деньги, и отчёт,
 * который их складывает, показывает картину лучше реальной ровно настолько,
 * насколько плохо работает отдел продаж.
 *
 * Заказы, оплаченные при получении, в выручку пока не попадают: у нас нет
 * места, где менеджер отмечает приём наличных. Когда появится — считать надо
 * будет здесь же, а не в виджете.
 */
class SalesReport
{
    public function __construct(
        private readonly Carbon $from,
        private readonly Carbon $to,
    ) {}

    public static function forDays(int $days): self
    {
        return new self(now()->subDays($days)->startOfDay(), now()->endOfDay());
    }

    public static function forMonth(): self
    {
        return new self(now()->startOfMonth(), now()->endOfMonth());
    }

    /** Полученные деньги за период. */
    public function revenue(): float
    {
        return (float) Payment::where('status', PaymentStatus::Succeeded)
            ->whereBetween('paid_at', [$this->from, $this->to])
            ->sum('amount');
    }

    public function paymentsCount(): int
    {
        return Payment::where('status', PaymentStatus::Succeeded)
            ->whereBetween('paid_at', [$this->from, $this->to])
            ->count();
    }

    public function ordersCount(): int
    {
        return $this->orders()->count();
    }

    /**
     * Средний чек по деньгам, а не по заявкам: делить выручку на все заявки,
     * включая неоплаченные, — значит занижать его в разы.
     */
    public function averageCheck(): float
    {
        $count = $this->paymentsCount();

        return $count > 0 ? round($this->revenue() / $count, 2) : 0.0;
    }

    /**
     * Доля заявок, дошедших до денег или до выполнения.
     * Отменённые остаются в знаменателе — иначе конверсия улучшается
     * от того, что менеджер отменяет неудобные заявки.
     */
    public function conversion(): float
    {
        $total = $this->ordersCount();

        if ($total === 0) {
            return 0.0;
        }

        $won = $this->orders()
            ->whereIn('status', [
                OrderStatus::Confirmed->value,
                OrderStatus::Scheduled->value,
                OrderStatus::Completed->value,
            ])
            ->count();

        return round($won / $total * 100, 1);
    }

    public function cancelledCount(): int
    {
        return $this->orders()->where('status', OrderStatus::Cancelled)->count();
    }

    /**
     * Выручка по дням. Дни без продаж возвращаются нулями — иначе график
     * склеивает разрывы и рисует рост там, где была тишина.
     *
     * @return Collection<string,float>
     */
    public function revenueByDay(): Collection
    {
        $paid = Payment::where('status', PaymentStatus::Succeeded)
            ->whereBetween('paid_at', [$this->from, $this->to])
            ->get()
            ->groupBy(fn (Payment $p) => $p->paid_at->format('Y-m-d'))
            ->map(fn (Collection $group) => (float) $group->sum('amount'));

        $days = collect();
        $cursor = $this->from->copy()->startOfDay();

        while ($cursor->lte($this->to)) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = $paid[$key] ?? 0.0;
            $cursor->addDay();
        }

        return $days;
    }

    /** Откуда приходят заявки — по этому решают, куда давать рекламу. */
    public function bySource(): Collection
    {
        return $this->orders()
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');
    }

    /**
     * Что покупают. Считаем по позициям заказов, а не по просмотрам карточек:
     * интерес и покупка — разные вещи.
     *
     * @return Collection<int,object>
     */
    public function topProducts(int $limit = 5): Collection
    {
        return OrderItem::query()
            ->whereHas('order', fn ($q) => $q->whereBetween('created_at', [$this->from, $this->to]))
            ->selectRaw('name, COUNT(*) as orders_count, SUM(price * quantity) as total')
            ->groupBy('name')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get();
    }

    private function orders()
    {
        return Order::whereBetween('created_at', [$this->from, $this->to]);
    }
}
