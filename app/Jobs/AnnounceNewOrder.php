<?php

namespace App\Jobs;

use App\Enums\OrderSource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Notifications\EmailClient;
use App\Notifications\TelegramClient;
use App\Notifications\WhatsAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Сообщает смене о новой заявке.
 *
 * Отдельной задачей, потому что заявка не имеет права упасть из-за мессенджера:
 * клиент уже нажал «отправить», и ошибка канала не должна превратиться
 * в ошибку оформления. По той же причине здесь ничего не выбрасывается наружу.
 */
class AnnounceNewOrder implements ShouldQueue
{
    use Queueable;

    public array $backoff = [10, 60];

    public function __construct(public Order $order) {}

    public function handle(TelegramClient $telegram, ?WhatsAppClient $whatsapp = null): void
    {
        $order = $this->order->fresh(['customer', 'items', 'assignments.servicePoint']);

        if ($order === null) {
            return;
        }

        $telegram->send($this->htmlMessage($order));
        ($whatsapp ?? app(WhatsAppClient::class))->send($order->whatsappText());
        app(EmailClient::class)->send($order, $this->link($order));
    }

    private function htmlMessage(Order $order): string
    {
        $lines = [
            '🔔 <b>Новая заявка '.e($order->number).'</b>',
            'Клиент: '.e($order->customer->name).' — '.e($order->customer->phone),
        ];

        if ($order->fulfillment) {
            $lines[] = 'Нужно: '.e($order->fulfillment->label());
        }

        if (filled($order->address)) {
            $lines[] = 'Адрес: '.e($order->address);
        }

        if ($order->payment_method) {
            $lines[] = 'Оплата: '.e($order->payment_method->label());
        }

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

        if ($order->source === OrderSource::Booking) {
            $when = $order->scheduled_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');
            $point = $order->assignments->first()?->servicePoint?->name;

            $slot = collect([$when, $point])->filter()->implode(', ');

            if ($slot !== '') {
                $lines[] = '📅 Запись: '.e($slot);
            }

            $lines[] = '⏱ Клиент уже выбрал время — подтвердить наличие стекла';
        } else {
            $lines[] = '⏱ Перезвонить в течение 15 минут';
        }

        if ($link = $this->link($order)) {
            $lines[] = '<a href="'.$link.'">Открыть заявку</a>';
        }

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
