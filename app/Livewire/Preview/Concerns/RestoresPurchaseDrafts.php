<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewOrderBook;
use Livewire\Attributes\Locked;

/** Owner-scoped session drafts for the prototype; persistent DB drafts follow in stage 2. */
trait RestoresPurchaseDrafts
{
    #[Locked]
    public ?array $editContext = null;

    private function draftSessionKey(?string $owner = null): string
    {
        return self::SESSION_KEY.'.owners.'.hash('sha256', $owner ?? (new PreviewOrderBook)->owner());
    }

    private function draftFields(): array
    {
        return ['cart', 'drafts', 'savedDrafts', 'mode', 'locale', 'fulfillment', 'name', 'phone', 'address',
            'list', 'importRows', 'importHash', 'importLimitError', 'importCorrections', 'importFile', 'showList', 'draftDetails'];
    }

    private function restoreCurrentDraft(): void
    {
        $legacy = session(self::SESSION_KEY, []);
        if (array_key_exists('cart', $legacy)) {
            if (! empty($legacy['list']) || ! empty($legacy['importRows'])) {
                $legacy['draftDetails']['wholesale']['review'] = array_intersect_key($legacy, array_flip([
                    'list', 'importRows', 'importHash', 'importLimitError', 'importCorrections', 'importFile', 'showList',
                ]));
            }
            session()->forget(self::SESSION_KEY);
            session()->put($this->draftSessionKey(), $legacy);
        }
        $stored = session($this->draftSessionKey(), []);
        $this->reset(...array_values(array_diff($this->draftFields(), ['locale'])));
        $this->reset('editing', 'editingVersion', 'editContext', 'accepted', 'consent', 'purchaseFile', 'scenario');
        foreach ($this->draftFields() as $key) {
            if (array_key_exists($key, $stored)) {
                $this->{$key} = $stored[$key];
            }
        }
        $this->restoreDraftMetadata($this->draftDetails[$this->mode] ?? []);
    }

    private function adoptGuestDraft(): void
    {
        $guest = session($this->draftSessionKey('guest'), []);
        $own = session($this->draftSessionKey(), []);
        $hasGuestWork = array_filter($guest['drafts'] ?? []) || ! empty($guest['savedDrafts'])
            || array_filter(array_column($guest['draftDetails'] ?? [], 'unresolved'))
            || ! empty($guest['list']) || ! empty($guest['importRows']);
        if (! $hasGuestWork) {
            session()->forget($this->draftSessionKey('guest'));
            $this->restoreCurrentDraft();

            return;
        }
        $rebind = function (array $details): array {
            $context = $details['edit'] ?? null;
            if ($context && $context['owner'] === 'guest') {
                $order = (new PreviewOrderBook)->find($context['number']);
                if ($order['created_in_preview'] ?? false) {
                    $details['edit']['owner'] = (new PreviewOrderBook)->owner();
                }
            }

            return $details;
        };
        $guest['draftDetails'] = array_map($rebind, $guest['draftDetails'] ?? []);
        foreach ($guest['savedDrafts'] ?? [] as $id => $draft) {
            $guest['savedDrafts'][$id]['details'] = $rebind($draft['details'] ?? []);
        }
        $ownReview = array_filter($own['draftDetails'] ?? [], fn ($details) => $this->draftHasReview($details));
        if (! array_filter($own['drafts'] ?? []) && empty($own['savedDrafts']) && ! array_filter(array_column($own['draftDetails'] ?? [], 'unresolved')) && ! $ownReview) {
            if ($guest) {
                session()->put($this->draftSessionKey(), $guest);
            }
        } else {
            foreach ($guest['drafts'] ?? [] as $mode => $cart) {
                $details = $guest['draftDetails'][$mode] ?? [];
                if ($cart || ($details['unresolved'] ?? []) || ! empty($details['review']['list']) || ! empty($details['review']['importRows'])) {
                    $own['savedDrafts'][] = ['mode' => $mode, 'cart' => $cart, 'details' => $details];
                }
            }
            foreach ($guest['savedDrafts'] ?? [] as $draft) {
                $own['savedDrafts'][] = $draft;
            }
            session()->put($this->draftSessionKey(), $own);
        }
        session()->forget($this->draftSessionKey('guest'));
        $this->restoreCurrentDraft();
    }

    private function archiveDraftForMode(string $targetMode): void
    {
        $this->rememberDraftMetadata();
        $this->drafts[$this->mode] = $this->cart;
        $cart = $this->drafts[$targetMode] ?? [];
        $details = $this->draftDetails[$targetMode] ?? [];
        if ($cart || ($details['unresolved'] ?? []) || $this->draftHasReview($details)) {
            $this->savedDrafts[] = ['mode' => $targetMode, 'cart' => $cart, 'details' => $details];
        }
    }

    public function draftHasReview(array $details): bool
    {
        return ! empty($details['review']['list']) || ! empty($details['review']['importRows']) || ! empty($details['review']['importLimitError']);
    }

    private function restoreEditingOrder(): void
    {
        $this->editing = $this->editContext !== null;
        $this->editingVersion = $this->editContext['version'] ?? null;
        if (! $this->editing || $this->editContext['owner'] !== (new PreviewOrderBook)->owner()) {
            return;
        }
        $order = (new PreviewOrderBook)->find($this->editContext['number']);
        if ($order) {
            $this->demoOrder = $order;
            $this->proposal = $order['proposal'] ?? null;
            $this->proposalSequence = $this->proposal['version'] ?? 0;
        }
    }

    public function editDraftConflict(): bool
    {
        if (! $this->editing) {
            return false;
        }
        if (! $this->editContext || $this->editContext['owner'] !== (new PreviewOrderBook)->owner()) {
            return true;
        }
        $order = (new PreviewOrderBook)->find($this->editContext['number']);

        return ! $order || $order['version'] !== $this->editingVersion
            || $this->demoOrder['number'] !== $this->editContext['number'] || $this->orderEditReason() !== null;
    }

    public function detachEditingDraft(): void
    {
        abort_unless($this->editing, 422);
        $this->editContext = null;
        $this->editing = false;
        $this->editingVersion = null;
        $this->accepted = $this->consent = false;
        $this->historyOrder = '';
        $this->notice = 'edit_detached';
        $this->saveDraft();
    }

    public function reloadEditingOrder(): void
    {
        abort_unless($this->editContext && $this->editContext['owner'] === (new PreviewOrderBook)->owner(), 403);
        $order = (new PreviewOrderBook)->find($this->editContext['number']);
        abort_unless($order, 404);
        $this->demoOrder = $order;
        $this->editOrder();
    }
}
