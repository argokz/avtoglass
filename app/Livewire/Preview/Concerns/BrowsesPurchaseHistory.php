<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewOrderBook;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

trait BrowsesPurchaseHistory
{
    #[Url(as: 'q', history: true)]
    public string $orderSearch = '';

    #[Url(as: 'status', history: true)]
    public string $orderStatus = 'all';

    #[Url(as: 'mode', history: true)]
    public string $orderMode = 'all';

    #[Url(as: 'period', history: true)]
    public string $orderPeriod = 'all';

    #[Url(as: 'page', history: true)]
    public int $orderPage = 1;

    #[Url(as: 'order', history: true)]
    public string $historyOrder = '';

    #[Locked]
    public string $historyOwner = 'guest';

    private function mountHistory(): void
    {
        $this->historyOwner = (new PreviewOrderBook)->owner();
        if ($this->historyOrder !== '') {
            if ($this->signedIn()) {
                $this->openHistoryOrder($this->historyOrder);
            } else {
                $this->screen = 'orders';
            }
        }
    }

    public function historyResults(): array
    {
        if (! $this->signedIn()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }
        $search = mb_strtolower(mb_substr(trim($this->orderSearch), 0, 100));
        $threshold = in_array($this->orderPeriod, ['30', '90'], true) ? now()->subDays((int) $this->orderPeriod)->timestamp : null;
        $orders = array_filter((new PreviewOrderBook)->all(), function ($order) use ($search, $threshold) {
            $haystack = $order['number'].' '.implode(' ', array_map(fn ($item) => __('shop.'.$item['title']).' '.$item['article'], $order['items'] ?? []));

            return ($this->orderStatus === 'all' || $order['status'] === $this->orderStatus)
                && ($this->orderMode === 'all' || $order['mode'] === $this->orderMode)
                && (! $threshold || strtotime($order['created_at']) >= $threshold)
                && ($search === '' || str_contains(mb_strtolower($haystack), $search));
        });
        usort($orders, function ($a, $b) {
            $closedA = in_array($a['status'], ['completed', 'cancelled'], true);
            $closedB = in_array($b['status'], ['completed', 'cancelled'], true);

            return ($closedA <=> $closedB) ?: (strcmp($b['created_at'], $a['created_at']) ?: strcmp($b['number'], $a['number']));
        });
        $total = count($orders);
        $pages = max(1, (int) ceil($total / 20));
        $page = max(1, min($this->orderPage, $pages));

        return ['rows' => array_slice($orders, ($page - 1) * 20, 20), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function historyPage(int $page): void
    {
        $this->orderPage = max(1, min($page, $this->historyResults()['pages']));
        $this->dispatch('history-ready');
    }

    public function resetHistoryFilters(): void
    {
        $this->reset('orderSearch', 'orderStatus', 'orderMode', 'orderPeriod', 'orderPage');
    }

    public function openHistoryOrder(string $number): void
    {
        abort_unless($this->signedIn(), 403);
        $order = (new PreviewOrderBook)->find($number);
        if (! $order) {
            $this->screen = 'orders';
            $this->historyOrder = '';
            $this->useLocale();
            $this->addError('history', __('shop.history_missing'));

            return;
        }
        $this->demoOrder = $order;
        $this->proposal = $order['proposal'] ?? null;
        $this->proposalSequence = $this->proposal['version'] ?? 0;
        $this->proposalConsent = false;
        $this->proposalReply = $this->changeText = '';
        $this->showChange = false;
        $this->reset('showManagerEditor', 'managerLines', 'managerAddress', 'managerSlot', 'managerFitmentChecked', 'managerEditorContext');
        $this->historyOrder = $number;
        $this->screen = 'order';
        $this->notice = '';
        $this->resetValidation();
    }

    private function persistDemoOrder(string $event = ''): void
    {
        $this->demoOrder += ['created_at' => now()->toIso8601String(), 'payment' => 'unpaid', 'paid' => 0, 'events' => [], 'items' => []];
        $this->demoOrder['items'] = array_intersect_key($this->demoOrder['items'], $this->demoOrder['cart']);
        foreach ($this->demoOrder['cart'] as $id => $quantity) {
            if (! isset($this->demoOrder['items'][$id]) && isset($this->offers()[$id])) {
                $this->demoOrder['items'][$id] = array_intersect_key($this->offers()[$id], array_flip(['title', 'article', 'manufacturer']));
            }
        }
        if ($event !== '') {
            $this->demoOrder['events'][] = ['kind' => $event, 'at' => now()->toIso8601String()];
        }
        $this->demoOrder['proposal'] = $this->proposal;
        (new PreviewOrderBook)->save($this->demoOrder);
        if ($this->signedIn()) {
            $this->historyOrder = $this->demoOrder['number'];
        }
    }

    public function orderEditReason(): ?string
    {
        if (in_array($this->demoOrder['status'], ['completed', 'cancelled'], true)) {
            return 'history_final_reason';
        }
        if (! in_array($this->demoOrder['status'], ['new', 'pending'], true)) {
            return 'history_confirmed_reason';
        }
        if (($this->demoOrder['payment'] ?? 'unpaid') !== 'unpaid' || ($this->demoOrder['paid'] ?? 0) > 0) {
            return 'history_payment_reason';
        }
        if (array_diff_key($this->demoOrder['cart'], $this->offers())) {
            return 'history_missing_reason';
        }

        return null;
    }

    public function canRequestChange(): bool
    {
        return ! in_array($this->demoOrder['status'], ['completed', 'cancelled'], true) && $this->orderEditReason() !== null;
    }

    public function cancelUnconfirmed(int $version): void
    {
        $this->useLocale();
        abort_unless($this->orderEditReason() === null, 403);
        $stored = (new PreviewOrderBook)->find($this->demoOrder['number']);
        if ($version !== $this->demoOrder['version'] || ($stored && $stored['version'] !== $version)) {
            throw ValidationException::withMessages(['history' => __('shop.proposal_conflict')]);
        }
        $this->demoOrder['status'] = 'cancelled';
        $this->demoOrder['version']++;
        if ($this->proposal) {
            $this->proposal['status'] = 'superseded';
        }
        $this->persistDemoOrder('history_cancelled_event');
    }
}
