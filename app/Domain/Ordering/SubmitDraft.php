<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Pricing\Models\CheckoutQuote;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Risk\AssessOrderRisk;
use App\Domain\Risk\FraudContext;
use App\Domain\Risk\FraudProvider;
use App\Domain\Risk\RiskLevel;
use App\Domain\Shared\Outbox\Outbox;
use App\Domain\Shared\PurchaseException;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\FulfillmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Jobs\ProcessOutboxEvent;
use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Offer;
use App\Models\Order;
use App\Models\ServicePoint;
use App\Models\StockReservation;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Finalise a checked quote: manager review application or automatic retail order.
 *
 * Must run inside Idempotency::execute (outer transaction). Lock order:
 * idempotency → draft → offers by id → overlapping assignments by id.
 */
final class SubmitDraft
{
    public function __construct(
        private readonly QuoteDraft $quotes,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function handle(BuyerContext $buyer, string $draftId, string $quoteId, int $expectedVersion, string $action, ?Order $reviewOrder = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SubmitDraft requires the idempotent submission transaction.');
        }

        // Lock offers before quote revalidation so shared→exclusive upgrades cannot deadlock.
        $draft = DraftAccess::query($buyer, $draftId)->lockForUpdate()->first();
        if (! $draft) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $buyer->assertMode($draft->sales_mode);
        $draft->loadMissing('items');
        $offerIds = $draft->items->pluck('offer_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($offerIds !== []) {
            Offer::query()->whereIn('id', $offerIds)->orderBy('id')->lockForUpdate()->get();
        }

        $quote = $this->quotes->assertCurrent($buyer, $draftId, $quoteId, $expectedVersion);
        $snapshot = $quote->jsonSerialize();
        $draft = DraftAccess::query($buyer, $draftId)->lockForUpdate()->firstOrFail();

        if ($reviewOrder !== null && $action === 'accept_proposal') {
            if ($reviewOrder->customer_id !== $buyer->customerId) {
                throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
            }

            return $this->confirmRetail($buyer, $draft, $quoteId, OrderProposalService::readySnapshot($snapshot), $draft->sales_mode === SalesMode::Wholesale, $reviewOrder);
        }

        if ($action === 'confirm_retail') {
            return $this->confirmRetail($buyer, $draft, $quoteId, $snapshot);
        }
        if ($action === 'confirm_wholesale') {
            $buyer->assertMode(SalesMode::Wholesale);
            if ($draft->sales_mode !== SalesMode::Wholesale) {
                throw new PurchaseException('WHOLESALE_REQUIRED', 403);
            }

            return $this->confirmRetail($buyer, $draft, $quoteId, $snapshot, true);
        }
        if ($action !== 'submit_for_review') {
            throw new PurchaseException('INVALID_ACTION', 422);
        }

        return $this->submitForReview($buyer, $draft, $quoteId, $snapshot);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function confirmRetail(BuyerContext $buyer, $draft, string $quoteId, array $snapshot, bool $wholesale = false, ?Order $reviewOrder = null): array
    {
        if ($draft->sales_mode === SalesMode::Wholesale && ! $wholesale) {
            throw new PurchaseException('REVIEW_REQUIRED', 409, [
                'readiness' => $snapshot['readiness'],
                'reasons' => array_values(array_unique([...($snapshot['reasons'] ?? []), 'WHOLESALE_REVIEW_REQUIRED'])),
            ]);
        }
        if (($snapshot['readiness'] ?? null) !== 'ready') {
            throw new PurchaseException('REVIEW_REQUIRED', 409, [
                'readiness' => $snapshot['readiness'] ?? null,
                'reasons' => $snapshot['reasons'] ?? [],
            ]);
        }
        if ($reviewOrder === null && ! config($wholesale ? 'ordering.wholesale_auto_checkout_enabled' : 'ordering.auto_checkout_enabled')) {
            throw new PurchaseException('AUTO_CHECKOUT_DISABLED', 409, [
                'quote_id' => $quoteId,
                'draft_id' => $draft->id,
            ]);
        }

        $risk = app(FraudProvider::class)->assess(new FraudContext(
            Customer::phoneHash($snapshot['contacts']['phone'] ?? null),
            request()->ip() ? hash_hmac('sha256', request()->ip(), (string) config('app.key')) : null,
            isset($snapshot['total']['amount_minor']) ? (float) Money::fromMinor($snapshot['total']['amount_minor'])->decimal() : null,
            $draft->sales_mode->value,
        ));
        if ($risk->level !== RiskLevel::Allow) {
            throw new PurchaseException('RISK_REVIEW_REQUIRED', 409);
        }

        $lines = collect($snapshot['items'] ?? [])->filter(fn ($line) => ($line['offer_id'] ?? null) !== null)->values();
        $offerIds = $lines->pluck('offer_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $offers = Offer::query()->whereIn('id', $offerIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($offers->count() !== count($offerIds)) {
            throw new PurchaseException('QUOTE_CHANGED', 409);
        }

        $held = StockReservation::query()->held()
            ->whereIn('offer_id', $offerIds)
            ->selectRaw('offer_id, sum(quantity) AS quantity')
            ->groupBy('offer_id')
            ->pluck('quantity', 'offer_id');

        foreach ($lines as $line) {
            $offer = $offers->get((int) $line['offer_id']);
            if (! $offer || ! $offer->is_active || (! $offer->auto_checkout_enabled && $reviewOrder === null)) {
                throw new PurchaseException('REVIEW_REQUIRED', 409, [
                    'readiness' => 'review_required',
                    'reasons' => ['AUTO_CHECKOUT_DISABLED'],
                ]);
            }
            $available = max(0, (int) $offer->stock_qty - (int) $held->get($offer->id, 0));
            if ($available < (int) $line['quantity']) {
                throw new PurchaseException('INSUFFICIENT_STOCK', 409, [
                    'offer_id' => $offer->id,
                    'available_quantity' => $available,
                ]);
            }
        }

        $fulfillment = $snapshot['fulfillment'] ?? [];
        $type = (string) ($fulfillment['type'] ?? 'pickup');
        $point = isset($fulfillment['service_point_id'])
            ? ServicePoint::query()->whereKey((int) $fulfillment['service_point_id'])->lockForUpdate()->first()
            : null;
        $duration = (int) ($fulfillment['duration_minutes'] ?? 0);
        $slotStart = isset($fulfillment['slot_start']) ? CarbonImmutable::parse($fulfillment['slot_start']) : null;

        if (in_array($type, ['installation', 'visit'], true) && $slotStart !== null) {
            $this->assertAndLockSlot($point, $slotStart, $duration);
        }

        $order = $this->createOrder($buyer, $draft, $snapshot, OrderStatus::Confirmed, $wholesale ? OrderSource::Wholesale : OrderSource::Cart, $reviewOrder);
        $this->addQuotedItems($order, $snapshot);
        $this->reserveAll($order, $lines);
        $assignmentId = null;

        if (in_array($type, ['installation'], true) && $slotStart !== null && $point !== null && $duration > 0) {
            $assignment = Assignment::create([
                'order_id' => $order->id,
                'type' => AssignmentType::Installation,
                'status' => AssignmentStatus::Planned,
                'service_point_id' => $point->id,
                'scheduled_at' => $slotStart->setTimezone(config('app.timezone')),
                'duration_minutes' => $duration,
            ]);
            $assignmentId = $assignment->id;
            $order->forceFill([
                'status' => OrderStatus::Scheduled,
                'scheduled_at' => $slotStart->setTimezone(config('app.timezone')),
            ])->save();
            Interaction::system($order, 'Автоподтверждение с записью на пост: '.$slotStart->setTimezone($point->timezone)->format('d.m.Y H:i'));
        } else {
            Interaction::system($order, 'Автоподтверждение: полный резерв без оплаты на этом шаге');
        }

        $this->finalizeDraft($draft, $quoteId);
        $this->enqueueAnnounce($order);

        return [
            'id' => $order->public_token,
            'order_id' => $order->id,
            'order_number' => $order->number,
            'path' => 'auto_confirmed',
            'status' => $order->status->value,
            'draft_id' => $draft->id,
            'quote_id' => $quoteId,
            'assignment_id' => $assignmentId,
            'reserved' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function submitForReview(BuyerContext $buyer, $draft, string $quoteId, array $snapshot): array
    {
        if (($snapshot['readiness'] ?? null) === 'incomplete') {
            throw new PurchaseException('QUOTE_INCOMPLETE', 409, [
                'readiness' => 'incomplete',
                'reasons' => $snapshot['reasons'] ?? [],
            ]);
        }

        $source = $draft->sales_mode === SalesMode::Wholesale ? OrderSource::Wholesale : OrderSource::Cart;
        $order = $this->createOrder($buyer, $draft, $snapshot, OrderStatus::New, $source);
        $this->addQuotedItems($order, $snapshot);
        Interaction::system($order, 'Заявка из черновика на согласование');
        $risk = app(AssessOrderRisk::class)->apply($order, request()->ip());
        if ($risk !== RiskLevel::Block) {
            $this->scheduleCallback($order, $draft->sales_mode === SalesMode::Wholesale);
        }
        $this->finalizeDraft($draft, $quoteId);
        if ($risk !== RiskLevel::Block) {
            $this->enqueueAnnounce($order);
        }

        return [
            'id' => $order->public_token,
            'order_id' => $order->id,
            'order_number' => $order->number,
            'path' => 'manager_review',
            'status' => OrderStatus::New->value,
            'draft_id' => $draft->id,
            'quote_id' => $quoteId,
            'assignment_id' => null,
            'reserved' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function createOrder(BuyerContext $buyer, $draft, array $snapshot, OrderStatus $status, OrderSource $source, ?Order $reviewOrder = null): Order
    {
        $name = trim((string) ($snapshot['contacts']['name'] ?? ''));
        $phone = (string) ($snapshot['contacts']['phone'] ?? '');
        $customer = Customer::forPhone($phone, $name);
        if ($buyer->customerId !== null && $customer->id !== $buyer->customerId) {
            // Bound account owns the draft; keep the account customer as order owner.
            $customer = Customer::query()->findOrFail($buyer->customerId);
        }

        $fulfillment = $snapshot['fulfillment'] ?? [];
        $type = FulfillmentType::tryFrom((string) ($fulfillment['type'] ?? ''))?->value;

        $attributes = [
            'purchase_draft_id' => $draft->id,
            'purchase_snapshot' => $snapshot,
            'purchase_state' => $status === OrderStatus::New ? 'review_required' : 'confirmed',
            'payment_method' => $snapshot['payment_method'] ?? 'cash',
            'currency' => 'KZT',
            'customer_id' => $customer->id,
            'status' => $status,
            'source' => $source,
            'fulfillment' => $type,
            'address' => $fulfillment['address'] ?? null,
            'city' => $fulfillment['city'] ?? currentCity(),
            'comment' => $this->commentFromSnapshot($snapshot),
            'total_amount' => isset($snapshot['total']['amount_minor'])
                ? Money::fromMinor((int) $snapshot['total']['amount_minor'])->decimal()
                : (isset($snapshot['known_total']['amount_minor'])
                    ? Money::fromMinor((int) $snapshot['known_total']['amount_minor'])->decimal()
                    : null),
        ];
        if ($reviewOrder !== null) {
            if (! in_array($reviewOrder->status, [OrderStatus::New, OrderStatus::InProgress], true) || $reviewOrder->payments()->where('status', 'succeeded')->exists()) {
                throw new PurchaseException('ORDER_CHANGED', 409);
            }
            unset($attributes['source']);
            $reviewOrder->items()->delete();
            $reviewOrder->forceFill($attributes)->save();
            $order = $reviewOrder;
        } else {
            $attributes['purchase_guest_owner_hash'] = $buyer->guestOwnerHash;
            $order = Order::create($attributes);
            $customer->increment('orders_count');
        }

        return $order;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function addQuotedItems(Order $order, array $snapshot): void
    {
        foreach ($snapshot['items'] ?? [] as $line) {
            if (($line['offer_id'] ?? null) === null) {
                continue;
            }
            $unit = $line['unit_price']['amount_minor'] ?? null;
            $order->items()->create([
                'product_id' => Offer::query()->whereKey((int) $line['offer_id'])->value('product_id'),
                'offer_id' => (int) $line['offer_id'],
                'name' => (string) ($line['name'] ?? 'Стекло'),
                'supplier_name' => $line['manufacturer'] ?? null,
                'price' => is_int($unit) ? Money::fromMinor($unit)->decimal() : '0.00',
                'quantity' => (int) $line['quantity'],
            ]);
        }
        foreach ($snapshot['service_items'] ?? [] as $service) {
            $unit = $service['unit_price']['amount_minor'] ?? null;
            if ($unit === null) {
                continue;
            }
            $order->items()->create([
                'product_id' => null,
                'offer_id' => null,
                'name' => (string) ($service['name'] ?? 'Работа'),
                'supplier_name' => null,
                'price' => Money::fromMinor((int) $unit)->decimal(),
                'quantity' => (int) ($service['quantity'] ?? 1),
            ]);
        }
        $order->load('items');
        if ($order->total_amount === null) {
            $order->recalculateTotal();
        }
    }

    /** @param  Collection<int,array<string,mixed>>  $lines */
    private function reserveAll(Order $order, $lines): void
    {
        $items = $order->items()->whereNotNull('offer_id')->get()->keyBy('offer_id');
        $days = (int) config('catalog.reservation.unpaid_days', 3);
        foreach ($lines as $line) {
            $offerId = (int) $line['offer_id'];
            $item = $items->get($offerId);
            if ($item === null) {
                throw new PurchaseException('QUOTE_CHANGED', 409);
            }
            StockReservation::create([
                'offer_id' => $offerId,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'quantity' => (int) $line['quantity'],
                'status' => ReservationStatus::Held,
                'expires_at' => in_array($order->payment_method?->value, ['card', 'kaspi'], true)
                    ? now()->addMinutes((int) config('ordering.online_reservation_minutes', 15)) : now()->addDays(max(1, $days)),
            ]);
        }
    }

    private function assertAndLockSlot(?ServicePoint $point, CarbonImmutable $start, int $duration): void
    {
        if ($point === null || $duration <= 0) {
            throw new PurchaseException('SLOT_UNAVAILABLE', 409);
        }
        $end = $start->addMinutes($duration);
        $legacyZone = config('app.timezone');
        $assignments = Assignment::query()->active()
            ->where('service_point_id', $point->id)
            ->where('scheduled_at', '<', $end->setTimezone($legacyZone)->format('Y-m-d H:i:s'))
            ->whereRaw("scheduled_at + duration_minutes * interval '1 minute' > ?", [$start->setTimezone($legacyZone)->format('Y-m-d H:i:s')])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $events = [];
        foreach ($assignments as $assignment) {
            $events[] = [max($start->timestamp, $assignment->scheduled_at->timestamp), 1];
            $events[] = [min($end->timestamp, $assignment->endsAt()->timestamp), -1];
        }
        sort($events);
        $busy = $peak = 0;
        foreach ($events as [, $delta]) {
            $busy += $delta;
            $peak = max($peak, $busy);
        }
        if ($peak >= (int) $point->bays) {
            throw new PurchaseException('SLOT_UNAVAILABLE', 409);
        }
    }

    private function finalizeDraft($draft, string $quoteId): void
    {
        $draft->forceFill(['status' => 'submitted'])->save();
        CheckoutQuote::query()->whereKey($quoteId)->where('draft_id', $draft->id)
            ->where('status', 'prepared')->update(['status' => 'accepted']);
    }

    private function enqueueAnnounce(Order $order): void
    {
        $event = $this->outbox->record('order.announced.v1', 'order', (string) $order->id, [
            'order_id' => $order->id,
            'order_number' => $order->number,
        ]);
        DB::afterCommit(fn () => ProcessOutboxEvent::dispatch($event->id));
    }

    private function scheduleCallback(Order $order, bool $wholesale): void
    {
        Task::create([
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'title' => $wholesale
                ? "Оптовая заявка {$order->number}"
                : "Перезвонить по заявке {$order->number}",
            'description' => $wholesale
                ? 'Рассмотреть спецификацию и предложить оплату.'
                : $order->comment,
            'due_at' => $wholesale ? now()->addHour() : now()->addMinutes(15),
        ]);
    }

    /** @param  array<string,mixed>  $snapshot */
    private function commentFromSnapshot(array $snapshot): ?string
    {
        $vehicle = $snapshot['vehicle'] ?? [];
        $parts = array_filter([
            isset($vehicle['model_id']) ? 'model_id='.$vehicle['model_id'] : null,
            isset($vehicle['year']) ? 'год '.$vehicle['year'] : null,
            'Черновик оформлен через ordering API',
        ]);
        foreach ($snapshot['items'] ?? [] as $line) {
            if (($line['offer_id'] ?? null) === null && filled($line['reference'] ?? null)) {
                $parts[] = 'Подбор: '.$line['reference'].'; количество '.($line['quantity'] ?? 1);
            }
            if (filled($line['comment'] ?? null)) {
                $parts[] = 'Комментарий: '.$line['comment'];
            }
        }

        return $parts !== [] ? implode('; ', $parts) : null;
    }
}
