<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewOrderBook;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Manager responses are simulated explicitly; no real reservations or payment calls. */
trait ReviewsManagerProposals
{
    #[Locked]
    public ?array $proposal = null;

    #[Locked]
    public int $proposalSequence = 0;

    public bool $proposalConsent = false;

    public string $proposalReply = '';

    public function prepareProposal(string $variant = 'ready'): void
    {
        abort_unless(in_array($variant, ['ready', 'waiting', 'expired', 'stock_changed', 'conflict'], true), 422);
        $this->refreshProposalOrder();
        abort_unless(array_diff_key($this->demoOrder['cart'], $this->offers()) === [], 422);
        abort_unless($this->canPrepareProposal(), 403);
        $this->publishProposal($variant, $this->demoOrder['cart'], $this->demoOrder['fulfillment'], [
            'address' => $this->demoOrder['address'] ?? '', 'point' => $this->demoOrder['point'] ?? 'demo-center',
            'slot' => $this->demoOrder['slot'] ?? null,
        ], 'changed');
    }

    private function refreshProposalOrder(?int $proposalVersion = null): void
    {
        $this->useLocale();
        $stored = (new PreviewOrderBook)->find($this->demoOrder['number']);
        if ($stored) {
            if ($stored['version'] !== $this->demoOrder['version']
                || ($proposalVersion !== null && ($stored['proposal']['version'] ?? null) !== $proposalVersion)) {
                throw ValidationException::withMessages(['proposal' => __('shop.proposal_conflict')]);
            }
            $this->demoOrder = $stored;
            $this->proposal = $stored['proposal'] ?? null;
            $this->proposalSequence = max($this->proposalSequence, $this->proposal['version'] ?? 0);
        }
    }

    public function loadLatestProposal(): void
    {
        $stored = (new PreviewOrderBook)->find($this->demoOrder['number']);
        abort_unless($stored, 404);
        $this->demoOrder = $stored;
        $this->proposal = $stored['proposal'] ?? null;
        $this->proposalSequence = $this->proposal['version'] ?? 0;
        $this->proposalConsent = false;
        $this->resetValidation();
        $this->dispatch('proposal-ready');
    }

    private function publishProposal(string $variant, array $cart, string $fulfillment, array $receipt, string $priceScenario): void
    {
        $prices = [];
        $available = $variant !== 'waiting';
        $orderOffers = $this->offers($this->demoOrder['mode']);
        $managerOffers = $this->offers($this->demoOrder['mode'], $priceScenario);
        $items = [];
        foreach ($cart as $id => $quantity) {
            $offer = $orderOffers[$id];
            $prices[$id] = $managerOffers[$id]['price'];
            $available = $available && $offer['stock'] >= $quantity && $prices[$id] !== null;
            $items[$id] = array_intersect_key($offer, array_flip(['title', 'article', 'manufacturer']));
        }
        $goods = 0;
        foreach ($cart as $id => $quantity) {
            $goods += $prices[$id] * $quantity;
        }
        $services = in_array($fulfillment, ['installation', 'visit'], true) ? 1000000 * array_sum($cart) : 0;
        $delivery = match ($fulfillment) {
            'delivery' => 300000, 'visit' => 450000, default => 0
        };
        if ($this->proposal) {
            $previous = $this->proposal;
            if ($previous['status'] === 'open') {
                $previous['status'] = 'superseded';
            }
            $this->demoOrder['proposalHistory'][] = $previous;
        }
        $this->proposal = [
            'version' => ++$this->proposalSequence, 'orderVersion' => $this->demoOrder['version'],
            'orderNumber' => $this->demoOrder['number'], 'status' => 'open', 'variant' => $variant,
            'previousTotal' => $this->demoOrder['total'], 'prices' => $prices,
            'cart' => $cart, 'items' => $items, 'fulfillment' => $fulfillment, ...$receipt, 'slotAvailable' => true,
            'goods' => $goods, 'services' => $services, 'delivery' => $delivery,
            'total' => in_array(null, $prices, true) ? null : $goods + $services + $delivery,
            'before' => array_intersect_key($this->demoOrder, array_flip(['cart', 'items', 'prices', 'fulfillment', 'address', 'point', 'slot', 'total'])),
            'available' => $available, 'kind' => in_array($this->demoOrder['status'], ['confirmed', 'scheduled'], true) ? 'change' : 'initial',
            'expiresAt' => ($variant === 'expired' ? now()->subMinute() : now()->addDay())->toIso8601String(),
        ];
        $this->proposalConsent = false;
        $this->proposalReply = '';
        $this->notice = '';
        $this->resetValidation();
        $this->screen = 'order';
        $this->dispatch('proposal-ready');
        $this->persistDemoOrder('history_proposal_event');
    }

    public function proposalBlocker(): ?string
    {
        if (! $this->proposal) {
            return 'proposal_missing';
        }
        if ($this->proposal['status'] !== 'open') {
            return 'proposal_'.$this->proposal['status'];
        }
        if ($this->proposal['orderNumber'] !== $this->demoOrder['number']
            || $this->proposal['orderVersion'] !== $this->demoOrder['version']) {
            return 'proposal_conflict';
        }
        if (CarbonImmutable::parse($this->proposal['expiresAt'])->isPast()) {
            return 'proposal_expired';
        }
        if (! $this->proposal['available']) {
            return $this->proposal['variant'] === 'stock_changed' ? 'proposal_stock_changed' : 'proposal_waiting';
        }
        if (in_array($this->proposal['fulfillment'], ['installation', 'visit'], true)
            && (! ($this->proposal['slotAvailable'] ?? true) || ! ($this->proposal['slot'] ?? null)
                || CarbonImmutable::parse($this->proposal['slot']['start'])->isPast())) {
            return 'proposal_slot_unavailable';
        }
        if (($this->demoOrder['payment'] ?? 'unpaid') === 'pending' || $this->hasPendingSettlement()) {
            return 'proposal_finance_pending';
        }

        return null;
    }

    public function acceptProposal(int $version): void
    {
        $this->useLocale();
        $this->refreshProposalOrder($version);
        if ($this->proposal && $this->proposal['version'] === $version && $this->proposal['status'] === 'accepted') {
            return;
        }
        $blocker = $this->proposalBlocker();
        if (! $this->proposal || $this->proposal['version'] !== $version) {
            $blocker = 'proposal_conflict';
        }
        if ($blocker) {
            throw ValidationException::withMessages(['proposal' => __('shop.'.$blocker)]);
        }
        $this->validate(['proposalConsent' => ['accepted']], ['proposalConsent.accepted' => __('shop.proposal_consent_error')]);
        if ($this->proposal['variant'] === 'conflict') {
            $this->proposal['status'] = 'superseded';
            $this->persistDemoOrder();
            throw ValidationException::withMessages(['proposal' => __('shop.proposal_conflict')]);
        }
        foreach ($this->proposal['cart'] as $id => $quantity) {
            if ($this->proposal['variant'] === 'stock_changed' || $this->offers()[$id]['stock'] < $quantity) {
                $this->proposal['available'] = false;
                $this->persistDemoOrder();
                throw ValidationException::withMessages(['proposal' => __('shop.proposal_stock_changed')]);
            }
        }
        $this->demoOrder['cart'] = $this->proposal['cart'];
        $this->demoOrder['prices'] = $this->proposal['prices'];
        $this->demoOrder['total'] = $this->proposal['total'];
        $this->demoOrder['fulfillment'] = $this->proposal['fulfillment'];
        foreach (['address', 'point', 'slot', 'items'] as $field) {
            $this->demoOrder[$field] = $this->proposal[$field] ?? ($field === 'items' ? [] : null);
        }
        $this->demoOrder['status'] = 'confirmed';
        $this->demoOrder['fitment_review_ids'] = [];
        $this->demoOrder['version']++;
        $this->proposal['status'] = 'accepted';
        $this->recordAcceptedSettlement();
        $this->notice = 'proposal_accepted';
        $this->resetValidation();
        $this->persistDemoOrder('proposal_accepted');
    }

    public function declineProposal(int $version): void
    {
        $this->useLocale();
        $this->refreshProposalOrder($version);
        abort_unless($this->proposal && $this->proposal['version'] === $version && $this->proposal['status'] === 'open', 409);
        $this->validate(['proposalReply' => ['required', 'string', 'min:3', 'max:500']], ['proposalReply.*' => __('shop.proposal_reply_error')]);
        $this->proposal['status'] = 'declined';
        $this->proposal['reply'] = $this->proposalReply;
        $this->proposalConsent = false;
        $this->notice = 'proposal_declined';
        $this->persistDemoOrder('proposal_declined');
    }

    public function requestFreshProposal(): void
    {
        $this->refreshProposalOrder($this->proposal['version'] ?? null);
        abort_unless($this->proposal && $this->proposal['status'] !== 'accepted', 409);
        $this->proposal['status'] = 'refresh_requested';
        $this->proposalConsent = false;
        $this->notice = 'proposal_refresh_requested';
        $this->persistDemoOrder('proposal_refresh_requested');
        $this->resetValidation();
    }
}
