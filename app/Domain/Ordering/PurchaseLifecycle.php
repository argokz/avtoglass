<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Enums\ReservationStatus;
use App\Models\Offer;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\Task;

final class PurchaseLifecycle
{
    public function fullyReserved(Order $order): bool
    {
        $items = $order->items()->whereNotNull('offer_id')->get();
        if ($items->isEmpty()) {
            return false;
        }
        $held = StockReservation::query()->held()->where('order_id', $order->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->get()->keyBy('order_item_id');

        return $items->every(fn ($item) => (int) ($held->get($item->id)?->quantity ?? 0) >= $item->quantity);
    }

    /** Caller holds the order lock. A received financial fact must survive an expired reservation. */
    public function paid(Order $order): void
    {
        if (! $order->purchase_draft_id || $order->outstanding() > 0) {
            return;
        }
        if ($order->status->isFinal() || $order->purchase_state === 'review_required' || ($order->risk_status && $order->risk_status->value !== 'allow')) {
            $this->review($order);

            return;
        }
        $items = $order->items()->whereNotNull('offer_id')->get();
        $offers = Offer::query()->whereIn('id', $items->pluck('offer_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $foreign = StockReservation::query()->held()->where('order_id', '!=', $order->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('offer_id', $offers->keys())->selectRaw('offer_id, sum(quantity) as qty')->groupBy('offer_id')->pluck('qty', 'offer_id');
        if ($items->isEmpty() || $items->contains(fn ($item) => ! $offers->has($item->offer_id)
            || ! $offers[$item->offer_id]->is_active || $offers[$item->offer_id]->stock_qty - (int) $foreign->get($item->offer_id, 0) < $item->quantity)) {
            $this->review($order);

            return;
        }
        foreach ($items as $item) {
            StockReservation::query()->updateOrCreate(['order_item_id' => $item->id], [
                'order_id' => $order->id, 'offer_id' => $item->offer_id, 'quantity' => $item->quantity,
                'status' => ReservationStatus::Held, 'expires_at' => null,
            ]);
        }
        $order->forceFill(['purchase_state' => 'paid'])->save();
    }

    private function review(Order $order): void
    {
        $order->forceFill(['purchase_state' => 'paid_review'])->save();
        Task::query()->firstOrCreate(['order_id' => $order->id, 'title' => 'Оплата получена: проверить исполнение'], [
            'customer_id' => $order->customer_id, 'description' => 'Оплата сохранена. Полный резерв или условия исполнения требуют проверки.', 'due_at' => now(),
        ]);
    }
}
