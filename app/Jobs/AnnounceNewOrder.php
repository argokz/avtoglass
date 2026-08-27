<?php

namespace App\Jobs;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Notifications\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Сообщает смене о новой заявке.
 *
 * Отдельной задачей, потому что заявка не имеет права упасть из-за телеграма:
 * клиент уже нажал «отправить», и ошибка мессенджера не должна превратиться
 * в ошибку оформления. По той же причине здесь ничего не выбрасывается наружу.
 */
class AnnounceNewOrder implements ShouldQueue
{
    use Queueable;

    public array $backoff = [10, 60];

    public function __construct(public Order $order) {}

    public function handle(TelegramClient $telegram): void
    {
        $order = $this->order->fresh();

        if ($order === null || ! $telegram->configured()) {
            return;
        }

        $telegram->send($this->message($order->load(['customer', 'items'])));
    }

    private function message(Order $order): string
    {
        $lines = [
            '🔔 <b>Новая заявка '.e($order->number).'</b>',
            'Клиент: '.e($order->customer->name).' — '.e($order->customer->phone),
        ];

        if ($order->items->isNotEmpty()) {
            $lines[] = 'Позиции: '.e($order->items->pluck('name')->implode(', '));
        }

        if ($order->total_amount !== null) {
            $lines[] = 'Сумма: '.number_format((float) $order->total_amount, 0, ',', ' ').' ₸';
        }

        if (filled($order->comment)) {
            $lines[] = 'Комментарий: '.e($order->comment);
        }

        $lines[] = 'Откуда: '.e($order->source->label());

        // Ссылка ведёт прямо в карточку: цель уведомления — чтобы менеджер
        // перезвонил, а не чтобы он потом искал заявку в списке.
        if ($link = $this->link($order)) {
            $lines[] = '<a href="'.$link.'">Открыть заявку</a>';
        }

        // Срок звонка пишем прямым текстом: это единственная цифра,
        // по которой потом разбирают, почему клиент ушёл.
        $lines[] = '⏱ Перезвонить в течение 15 минут';

        return implode("\n", $lines);
    }

    /**
     * Панель указываем явно: задача выполняется в воркере, где текущей
     * панели нет и Filament не сможет догадаться сам.
     */
    private function link(Order $order): ?string
    {
        try {
            return OrderResource::getUrl('edit', ['record' => $order->getKey()], panel: 'admin');
        } catch (\Throwable) {
            return null;
        }
    }
}
