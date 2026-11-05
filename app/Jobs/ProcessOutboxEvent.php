<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Shared\Outbox\OutboxEvent;
use App\Models\Order;
use App\Notifications\TelegramClient;
use App\Notifications\WhatsAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Delivers committed outbox rows. Failures stay on the outbox, never on the order. */
final class ProcessOutboxEvent implements ShouldQueue
{
    use Queueable;

    public array $backoff = [5, 30, 120];

    public function __construct(public string $outboxId) {}

    public function handle(): void
    {
        $event = DB::transaction(function () {
            $event = OutboxEvent::query()->whereKey($this->outboxId)->lockForUpdate()->first();
            if (! $event || $event->attempts >= 10 || ! in_array($event->status, ['pending', 'failed'], true)) {
                return null;
            }
            if ($event->available_at->gt(CarbonImmutable::now('UTC'))) {
                return null;
            }
            $event->forceFill([
                'status' => 'processing',
                'attempts' => $event->attempts + 1,
                'available_at' => CarbonImmutable::now('UTC')->addMinutes(10),
            ])->save();

            return $event;
        }, 3);

        if ($event === null) {
            return;
        }

        try {
            $this->deliver($event);
            $event->forceFill([
                'status' => 'done',
                'processed_at' => CarbonImmutable::now('UTC'),
            ])->save();
        } catch (Throwable) {
            $event->forceFill([
                'status' => $event->attempts >= 10 ? 'failed' : 'pending',
                'available_at' => CarbonImmutable::now('UTC')->addSeconds(min(600, 5 * $event->attempts)),
            ])->save();
        }
    }

    private function deliver(OutboxEvent $event): void
    {
        if ($event->event_type === 'order.announced.v1') {
            $orderId = (int) ($event->payload['order_id'] ?? 0);
            $order = Order::query()->find($orderId);
            if ($order === null) {
                return;
            }
            (new AnnounceNewOrder($order))->handle(
                app(TelegramClient::class),
                app(WhatsAppClient::class),
            );

            return;
        }

        throw new \RuntimeException('Unknown outbox event type: '.$event->event_type);
    }
}
