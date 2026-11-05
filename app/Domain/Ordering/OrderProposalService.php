<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\PurchaseException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderProposalService
{
    public function propose(Order $order, User $staff, array $changes = []): OrderProposal
    {
        $buyer = BuyerContext::review($staff, $order->customer_id);

        return DB::transaction(function () use ($order, $staff, $buyer, $changes) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->purchase_state !== 'review_required' || ! $order->purchase_draft_id || $order->isPaid()) {
                throw new PurchaseException('ORDER_CHANGED', 409);
            }
            $original = Models\OrderDraft::query()->with('items')->findOrFail($order->purchase_draft_id);
            $drafts = app(DraftService::class);
            $draft = $drafts->create($buyer, $original->sales_mode);
            $items = $original->items->map(fn ($line) => ['line_id' => (string) Str::uuid(), 'offer_id' => $line->offer_id,
                'reference' => $line->reference, 'quantity' => $line->quantity, 'selection_mode' => $line->selection_mode,
                'article_confirmed' => $line->article_confirmed, 'manufacturer_confirmed' => $line->manufacturer_confirmed, 'comment' => $line->comment])->all();
            $draft = $drafts->update($buyer, $draft->id, $draft->version, array_replace([
                'items' => $items, 'contacts' => $original->contacts, 'vehicle' => $original->vehicle,
                'fulfillment' => $original->fulfillment, 'payment_method' => $original->payment_method ?? 'cash',
            ], $changes));
            $quote = app(QuoteDraft::class)->prepare($buyer, $draft->id, $draft->version)->jsonSerialize();
            if (self::readySnapshot($quote)['readiness'] !== 'ready') {
                throw new PurchaseException('REVIEW_REQUIRED', 422, ['reasons' => self::readySnapshot($quote)['reasons']]);
            }
            $version = (int) OrderProposal::query()->where('order_id', $order->id)->max('version') + 1;
            OrderProposal::query()->where('order_id', $order->id)->where('status', 'prepared')->update(['status' => 'superseded']);

            return OrderProposal::query()->create(['id' => (string) Str::uuid(), 'order_id' => $order->id,
                'draft_id' => $draft->id, 'quote_id' => $quote['id'], 'version' => $version, 'snapshot' => $quote,
                'status' => 'prepared', 'expires_at' => $quote['expires_at'], 'created_by' => $staff->id]);
        });
    }

    public function accept(BuyerContext $buyer, string $token, string $proposalId): array
    {
        return DB::transaction(function () use ($buyer, $token, $proposalId) {
            $order = app(CustomerPurchases::class)->owned($buyer, $token);
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $proposal = OrderProposal::query()->whereKey($proposalId)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status === 'accepted') {
                return app(CustomerPurchases::class)->view($order);
            }
            if ($proposal->status !== 'prepared' || $proposal->expires_at->lte(now()) || $order->purchase_state !== 'review_required') {
                throw new PurchaseException('QUOTE_NOT_CURRENT', 409);
            }
            $reviewer = BuyerContext::review(User::query()->findOrFail($proposal->created_by), $order->customer_id, $buyer->locale);
            app(SubmitDraft::class)->handle($reviewer, $proposal->draft_id, $proposal->quote_id, (int) $proposal->snapshot['draft_version'], 'accept_proposal', $order);
            $proposal->forceFill(['status' => 'accepted'])->save();

            return app(CustomerPurchases::class)->view($order->refresh());
        });
    }

    public static function readySnapshot(array $quote): array
    {
        // A manager may approve manual-sale policy; unknown prices, stock and fitment stay blocking.
        $ignored = ['AUTO_CHECKOUT_DISABLED', 'WHOLESALE_REVIEW_REQUIRED'];
        $quote['reasons'] = array_values(array_diff($quote['reasons'] ?? [], $ignored));
        $quote['issues'] = array_values(array_filter($quote['issues'] ?? [], fn ($issue) => ! in_array($issue['code'], $ignored, true)));
        $quote['readiness'] = $quote['readiness'] === 'incomplete' ? 'incomplete' : ($quote['reasons'] === [] ? 'ready' : 'review_required');

        return $quote;
    }
}
