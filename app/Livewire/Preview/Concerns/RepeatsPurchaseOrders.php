<?php

namespace App\Livewire\Preview\Concerns;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait RepeatsPurchaseOrders
{
    #[Locked]
    public array $unresolvedRows = [];

    #[Locked]
    public array $replacementIds = [];

    #[Locked]
    public ?string $repeatedFrom = null;

    #[Locked]
    public array $repeatPrices = [];

    #[Locked]
    public array $draftDetails = [];

    public function loadRepeatExample(): void
    {
        $this->demoOrder = [
            'number' => 'DEMO-2090', 'mode' => 'retail', 'status' => 'completed', 'version' => 1,
            'cart' => [1 => 1, 99 => 2, 3 => 1], 'prices' => [1 => 3500000, 99 => 3200000, 3 => 4100000],
            'total' => 14000000, 'changes' => [], 'fulfillment' => 'pickup',
            'items' => [99 => ['title' => 'archived_glass', 'article' => 'OLD-CAMRY', 'manufacturer' => 'AGC']],
        ];
        $this->proposal = null;
        $this->showChange = false;
        $this->screen = 'order';
        $this->persistDemoOrder('created');
    }

    public function repeatOrder(): void
    {
        $this->archiveDraftForMode($this->demoOrder['mode']);
        $this->mode = $this->demoOrder['mode'];
        $this->restoreDraftMetadata([]);
        $this->cart = [];
        $this->unresolvedRows = [];
        $this->replacementIds = [];
        $this->repeatedFrom = $this->demoOrder['number'];
        $this->repeatPrices = $this->demoOrder['prices'];
        $this->assistantReviewIds = $this->demoOrder['fitment_review_ids'] ?? [];
        foreach ($this->demoOrder['cart'] as $id => $quantity) {
            if (isset($this->offers()[$id])) {
                $this->cart[$id] = $quantity;
            } else {
                $this->unresolvedRows[$id] = [...$this->demoOrder['items'][$id], 'quantity' => $quantity, 'oldPrice' => $this->demoOrder['prices'][$id]];
            }
        }
        $this->mode = $this->demoOrder['mode'];
        $this->fulfillment = $this->demoOrder['fulfillment'];
        $this->scenario = 'changed';
        $this->accepted = false;
        $this->consent = false;
        $this->editing = false;
        $this->editContext = null;
        $this->editingVersion = null;
        $this->screen = 'checkout';
        $this->historyOrder = '';
        $this->notice = 'repeat_note';
        $this->saveDraft();
    }

    public function excludeMissingItem(int $id): void
    {
        abort_unless(isset($this->unresolvedRows[$id]), 404);
        unset($this->unresolvedRows[$id]);
        $this->accepted = false;
        $this->saveDraft();
    }

    public function replaceMissingItem(int $id, int $offerId): void
    {
        abort_unless(isset($this->unresolvedRows[$id], $this->offers()[$offerId]), 422);
        $this->useLocale();
        $quantity = ($this->cart[$offerId] ?? 0) + $this->unresolvedRows[$id]['quantity'];
        if (! $this->validQuantity($quantity)) {
            throw ValidationException::withMessages(['missing' => __('shop.fix_quantity')]);
        }
        $this->cart[$offerId] = $quantity;
        $this->replacementIds[$offerId] = true;
        unset($this->unresolvedRows[$id]);
        $this->accepted = false;
        $this->notice = 'repeat_replaced';
        $this->saveDraft();
    }

    private function rememberDraftMetadata(): void
    {
        $this->draftDetails[$this->mode] = [
            'unresolved' => $this->unresolvedRows, 'replacements' => $this->replacementIds, 'source' => $this->repeatedFrom, 'prices' => $this->repeatPrices,
            'edit' => $this->editContext, 'scenario' => $this->scenario, 'fulfillment' => $this->fulfillment, 'termsChanged' => $this->customerTermsChanged,
            'assistantReview' => $this->assistantReviewIds,
            'name' => $this->name, 'phone' => $this->phone, 'address' => $this->address,
            'termsStatus' => $this->signedIn() ? $this->customerData()['wholesale']['status'] : 'none',
            'quotedPrices' => array_intersect_key(array_column($this->offers(), 'price', 'id'), $this->cart),
        ];
        if ($this->mode === 'wholesale') {
            $this->draftDetails[$this->mode]['review'] = array_intersect_key(get_object_vars($this), array_flip([
                'list', 'importRows', 'importHash', 'importLimitError', 'importCorrections', 'importFile', 'showList',
            ]));
        }
    }

    private function restoreDraftMetadata(array $details): void
    {
        $this->unresolvedRows = $details['unresolved'] ?? [];
        $this->replacementIds = $details['replacements'] ?? [];
        $this->assistantReviewIds = $details['assistantReview'] ?? [];
        $this->repeatedFrom = $details['source'] ?? null;
        $this->repeatPrices = $details['prices'] ?? [];
        $this->editContext = $details['edit'] ?? null;
        $this->customerTermsChanged = $details['termsChanged'] ?? false;
        $currentTerms = $this->signedIn() ? $this->customerData()['wholesale']['status'] : 'none';
        if ($this->mode === 'wholesale' && $this->cart && isset($details['termsStatus']) && $details['termsStatus'] !== $currentTerms) {
            $this->customerTermsChanged = true;
            $this->repeatPrices = $details['quotedPrices'] ?? $this->repeatPrices;
            $this->accepted = false;
        }
        $this->scenario = $details['scenario'] ?? 'ready';
        foreach (['fulfillment', 'name', 'phone', 'address'] as $field) {
            if (array_key_exists($field, $details)) {
                $this->{$field} = $details[$field];
            }
        }
        $this->restoreEditingOrder();
        if ($this->mode === 'wholesale') {
            $this->reset('list', 'importRows', 'importHash', 'importLimitError', 'importCorrections', 'importFile', 'showList', 'purchaseFile');
            foreach ($details['review'] ?? [] as $field => $value) {
                if (in_array($field, ['list', 'importRows', 'importHash', 'importLimitError', 'importCorrections', 'importFile', 'showList'], true)) {
                    $this->{$field} = $value;
                }
            }
        }
    }
}
