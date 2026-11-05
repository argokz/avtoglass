<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Actions\StartPayment;
use App\Domain\Identity\BuyerContext;
use App\Domain\Pricing\Money;
use App\Domain\Shared\PurchaseException;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final class CustomerPurchases
{
    /**
     * Сколько ждём начатую оплату, прежде чем разрешить отмену.
     * Банк может прислать «оплачено» через несколько минут — отменённый
     * и при этом оплаченный заказ хуже, чем минута ожидания.
     */
    public const PENDING_PAYMENT_MINUTES = 30;

    /** Номер заказа так, как его пишет человек: «АС-000078», «ac-78», «78». */
    public function ownedByNumber(BuyerContext $buyer, string $number): Order
    {
        $order = Order::query()->where('number', Order::normalizeNumber($number))->first();
        if ($order === null) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }

        return $this->owned($buyer, $order->public_token);
    }

    /**
     * Почему покупатель не может отменить заказ сам; null — может.
     *
     * Сам — только пока стекло не согласовано и денег нет. Дальше
     * зарезервирован склад, назначен мастер или прошла оплата — это
     * возврат и перепланирование, их ведёт менеджер.
     */
    public function cancelBlocker(Order $order): ?string
    {
        if ($order->status === OrderStatus::Cancelled) {
            return 'ALREADY_CANCELLED';
        }
        if (! in_array($order->status, [OrderStatus::New, OrderStatus::InProgress], true)) {
            return 'MANAGER_REQUIRED';
        }
        $payments = $order->payments()->where(fn ($query) => $query->where('status', PaymentStatus::Succeeded)
            ->orWhere(fn ($pending) => $pending->where('status', PaymentStatus::Pending)
                ->where('created_at', '>', now()->subMinutes(self::PENDING_PAYMENT_MINUTES))));

        return $payments->exists() ? 'PAYMENT_EXISTS' : null;
    }

    /** Отмена покупателем. Повторная отмена того же заказа — не ошибка. */
    public function cancel(BuyerContext $buyer, string $token, string $reason = ''): Order
    {
        $reason = mb_substr(trim($reason), 0, 500);

        return DB::transaction(function () use ($buyer, $token, $reason) {
            $owned = $this->owned($buyer, $token);
            $order = Order::query()->whereKey($owned->id)->lockForUpdate()->firstOrFail();
            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }
            if (($blocker = $this->cancelBlocker($order)) !== null) {
                throw new PurchaseException($blocker, 409);
            }
            if (! $order->transitionTo(OrderStatus::Cancelled)) {
                throw new PurchaseException('MANAGER_REQUIRED', 409);
            }
            Interaction::system($order, 'Покупатель отменил заказ сам'.($reason !== '' ? ': '.$reason : ''));
            // Менеджер мог уже звонить поставщику — задача, чтобы он узнал не из отчёта.
            Task::query()->create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'title' => 'Клиент отменил заказ '.$order->number,
                'description' => $reason !== '' ? 'Причина: '.$reason : 'Причину не указал.',
                'due_at' => now()->addMinutes(30),
            ]);

            return $order;
        });
    }

    public function owned(BuyerContext $buyer, string $token): Order
    {
        $buyer = $buyer->fresh();
        $order = Order::query()->where('public_token', $token)->first();
        if ($order && $buyer->customerId !== null && ($order->customer_id === $buyer->customerId
            || DB::table('purchase_access_grants')->where('order_id', $order->id)->where('customer_id', $buyer->customerId)->exists())) {
            return $order;
        }
        if ($order && $buyer->guestOwnerHash && ($order->purchase_guest_owner_hash === $buyer->guestOwnerHash || $order->purchase_draft_id && DraftAccess::query($buyer, $order->purchase_draft_id)->exists())) {
            return $order;
        }

        throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
    }

    public function recent(BuyerContext $buyer): array
    {
        $buyer = $buyer->fresh();
        if ($buyer->customerId === null) {
            throw new PurchaseException('AUTH_REQUIRED', 401);
        }

        return Order::query()->where(fn ($q) => $q->where('customer_id', $buyer->customerId)->orWhereIn('id', DB::table('purchase_access_grants')->where('customer_id', $buyer->customerId)->select('order_id')))
            ->latest()->limit(20)->get()->map(fn ($order) => $this->view($order))->all();
    }

    public function view(Order $order): array
    {
        return ['id' => $order->public_token, 'number' => $order->number, 'status' => $order->status->value,
            'status_label' => app()->getLocale() === 'kk' ? match ($order->status->value) {
                'new' => 'Жаңа өтінім', 'in_progress' => 'Қаралуда', 'confirmed' => 'Расталған', 'scheduled' => 'Жоспарланған', 'completed' => 'Аяқталған', 'cancelled' => 'Бас тартылған', default => $order->status->label(),
            } : $order->status->label(), 'purchase_state' => $order->purchase_state,
            'paid' => $order->isPaid(), 'payment_method' => $order->payment_method?->value,
            'can_pay' => app(StartPayment::class)->isPayable($order),
            'can_cancel' => $this->cancelBlocker($order) === null,
            'date' => $order->created_at?->format('d.m.Y'),
            'total' => $order->total_amount === null ? null : Money::fromDecimal($order->total_amount)->jsonSerialize()];
    }

    public function repeat(BuyerContext $buyer, string $token, string $key): array
    {
        $order = $this->owned($buyer, $token);
        $mode = ($order->purchase_snapshot['sales_mode'] ?? $order->source?->value) === 'wholesale' ? SalesMode::Wholesale : SalesMode::Retail;
        $commands = app(PurchaseCommands::class);
        $draft = $commands->createDraft($buyer, $key.':create', $mode)->data;
        $items = [];
        foreach ($order->items()->whereNotNull('product_id')->get() as $line) {
            $offer = $line->offer_id ? Offer::query()->with(['supplier', 'product'])->find($line->offer_id) : null;
            $active = $offer && $offer->is_active && $offer->supplier?->is_active && $offer->product?->is_published;
            $hash = hash('sha256', $key.':'.$line->id);
            $lineId = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
            $items[] = ['line_id' => $lineId, 'offer_id' => $active ? $offer->id : null,
                'reference' => $offer?->article ?: mb_substr($line->name, 0, 128), 'quantity' => (int) $line->quantity,
                'selection_mode' => 'vehicle_fitment', 'comment' => 'Повтор заказа '.$order->number];
        }
        $customer = $buyer->customerId ? Customer::query()->find($buyer->customerId) : null;

        $result = $commands->updateDraft($buyer, $draft['id'], $key.':items', $draft['version'], ['items' => $items,
            'contacts' => ['name' => $customer?->name, 'phone' => $customer?->phone],
            'vehicle' => $order->purchase_snapshot['vehicle'] ?? [],
            'fulfillment' => ['type' => 'pickup', 'city' => $order->city]])->data;
        app(ActiveDraft::class)->bind($buyer, $result['id']);

        return $result;
    }

    public function requestChange(BuyerContext $buyer, string $token, string $description): Task
    {
        if (trim($description) === '' || mb_strlen($description) > 1000) {
            throw new PurchaseException('INVALID_CHANGE_REQUEST', 422);
        }

        return DB::transaction(function () use ($buyer, $token, $description) {
            $order = $this->owned($buyer, $token);
            Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return Task::query()->firstOrCreate(['order_id' => $order->id, 'title' => 'Изменение заказа '.substr(hash('sha256', $description), 0, 12)],
                ['customer_id' => $order->customer_id, 'description' => $description, 'due_at' => now()->addMinutes(15)]);
        });
    }
}
