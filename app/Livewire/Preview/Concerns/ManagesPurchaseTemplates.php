<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewCustomerData;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ManagesPurchaseTemplates
{
    public string $templateName = '';

    #[Locked]
    public ?int $templateEditingId = null;

    public function savePurchaseTemplate(): void
    {
        $data = (new PreviewCustomerData)->read();
        $this->useLocale();
        $this->templateName = trim($this->templateName);
        $this->validate(['templateName' => ['required', 'string', 'max:80']], ['templateName.*' => __('shop.template_name_invalid')]);
        if ($this->templateEditingId !== null) {
            abort_unless(isset($data['templates'][$this->templateEditingId]), 404);
            $data['templates'][$this->templateEditingId]['name'] = $this->templateName;
        } else {
            if ($this->mode !== 'wholesale' || (! $this->cart && ! $this->unresolvedRows) || $this->invalidQuantities() || $this->hasPendingImport()) {
                throw ValidationException::withMessages(['templateName' => __('shop.template_selection_required')]);
            }
            if (count($data['templates']) >= 50) {
                throw ValidationException::withMessages(['templateName' => __('shop.template_limit')]);
            }
            $items = [];
            foreach ($this->cart as $id => $quantity) {
                $items[$id] = [...array_intersect_key($this->offers()[$id], array_flip(['title', 'article', 'manufacturer'])), 'quantity' => $quantity];
            }
            foreach ($this->unresolvedRows as $id => $row) {
                $items[$id] = array_intersect_key($row, array_flip(['title', 'article', 'manufacturer', 'quantity']));
            }
            $id = ++$data['templateSequence'];
            $data['templates'][$id] = ['id' => $id, 'name' => $this->templateName, 'items' => $items];
        }
        (new PreviewCustomerData)->save($data);
        $this->reset('templateName', 'templateEditingId');
        $this->notice = 'template_saved';
    }

    public function renamePurchaseTemplate(int $id): void
    {
        $template = $this->customerData()['templates'][$id] ?? null;
        abort_unless($template, 404);
        $this->templateEditingId = $id;
        $this->templateName = $template['name'];
        $this->dispatch('template-name-ready');
    }

    public function cancelTemplateRename(): void
    {
        $this->reset('templateName', 'templateEditingId');
        $this->resetValidation('templateName');
    }

    public function deletePurchaseTemplate(int $id): void
    {
        $data = (new PreviewCustomerData)->read();
        abort_unless(isset($data['templates'][$id]), 404);
        unset($data['templates'][$id]);
        (new PreviewCustomerData)->save($data);
        if ($this->templateEditingId === $id) {
            $this->cancelTemplateRename();
        }
        $this->notice = 'template_deleted';
    }

    public function applyPurchaseTemplate(int $id): void
    {
        $data = (new PreviewCustomerData)->read();
        $template = $data['templates'][$id] ?? null;
        abort_unless($template, 404);
        $review = $this->draftDetails['wholesale']['review'] ?? [];
        $pendingSavedRows = array_filter($review['importRows'] ?? [], fn ($row) => ! in_array($row['state'], ['applied', 'skipped'], true));
        $pendingSavedText = ! empty($review['list']) && ($review['importHash'] ?? null) !== hash('sha256', $review['list']);
        if ($this->hasPendingImport() || $this->purchaseFile !== null || $pendingSavedRows || $pendingSavedText || ($review['importLimitError'] ?? null)) {
            $this->useLocale();
            throw ValidationException::withMessages(['templateName' => __('shop.validation_import')]);
        }
        $this->drafts[$this->mode] = $this->cart;
        $this->rememberDraftMetadata();
        $previous = $this->drafts['wholesale'] ?? [];
        $details = $this->draftDetails['wholesale'] ?? [];
        if ($previous || ($details['unresolved'] ?? []) || $this->draftHasReview($details)) {
            $this->savedDrafts[] = ['mode' => 'wholesale', 'cart' => $previous, 'details' => $details];
        }
        $this->mode = 'wholesale';
        $this->restoreDraftMetadata([]);
        $this->cart = [];
        foreach ($template['items'] as $offerId => $item) {
            if (isset($this->offers()[$offerId])) {
                $this->cart[$offerId] = $item['quantity'];
            } else {
                $this->unresolvedRows[$offerId] = [...$item, 'oldPrice' => null];
            }
        }
        $this->fulfillment = 'pickup';
        $this->historyOrder = '';
        $this->accepted = $this->consent = false;
        $this->screen = 'checkout';
        $this->notice = 'template_applied';
        $this->saveDraft();
    }
}
