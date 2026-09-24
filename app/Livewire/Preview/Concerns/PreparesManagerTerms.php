<?php

namespace App\Livewire\Preview\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Editable manager fixtures; these controls exist only in the preview environment. */
trait PreparesManagerTerms
{
    public bool $showManagerEditor = false;

    public array $managerLines = [];

    public string $managerFulfillment = 'pickup';

    public string $managerAddress = '';

    public string $managerPoint = 'demo-center';

    public string $managerSlot = '';

    public bool $managerFitmentChecked = false;

    #[Locked]
    public ?array $managerEditorContext = null;

    public function canPrepareProposal(): bool
    {
        return in_array($this->demoOrder['status'], ['new', 'pending'], true)
            || (in_array($this->demoOrder['status'], ['confirmed', 'scheduled'], true) && $this->demoOrder['changes'] !== []);
    }

    public function proposalSlotOptions(): array
    {
        $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay()->startOfDay();
        $slots = [];
        foreach ([10, 14] as $hour) {
            $start = $tomorrow->setHour($hour);
            $slots[$start->format('Y-m-d-H')] = ['start' => $start->toIso8601String(), 'end' => $start->addHours(2)->toIso8601String()];
        }

        return $slots;
    }

    public function openProposalEditor(): void
    {
        $this->refreshProposalOrder();
        abort_unless($this->canPrepareProposal(), 403);
        $this->managerLines = [];
        foreach ($this->demoOrder['cart'] as $id => $quantity) {
            $this->managerLines[$id] = ['offer' => $id, 'quantity' => $quantity, 'remove' => false];
        }
        $this->managerFulfillment = $this->demoOrder['fulfillment'];
        $this->managerAddress = $this->demoOrder['address'] ?? '';
        $this->managerPoint = $this->demoOrder['point'] ?? 'demo-center';
        $this->managerSlot = '';
        $this->managerFitmentChecked = false;
        $this->managerEditorContext = ['number' => $this->demoOrder['number'], 'version' => $this->demoOrder['version']];
        $this->showManagerEditor = true;
        $this->resetValidation();
        $this->dispatch('manager-editor-ready');
    }

    public function sendEditedProposal(): void
    {
        $this->useLocale();
        $this->refreshProposalOrder();
        abort_unless($this->canPrepareProposal(), 403);
        if ($this->managerEditorContext !== ['number' => $this->demoOrder['number'], 'version' => $this->demoOrder['version']]) {
            throw ValidationException::withMessages(['manager' => __('shop.proposal_conflict')]);
        }
        $this->validate([
            'managerLines' => ['required', 'array', 'max:500'],
            'managerLines.*.offer' => ['required', 'integer'],
            'managerLines.*.quantity' => ['required', 'integer', 'between:1,99'],
            'managerLines.*.remove' => ['required', 'boolean'],
            'managerFulfillment' => ['required', $this->demoOrder['mode'] === 'wholesale' ? 'in:pickup,delivery' : 'in:pickup,delivery,installation,visit'],
            'managerAddress' => [in_array($this->managerFulfillment, ['delivery', 'visit'], true) ? 'required' : 'nullable', 'string', 'max:500'],
            'managerPoint' => ['required', 'in:demo-center'],
            'managerFitmentChecked' => ['accepted'],
        ], [
            'managerLines.*' => __('shop.manager_lines_invalid'), 'managerLines.*.*.*' => __('shop.manager_lines_invalid'),
            'managerAddress.*' => __('shop.validation_address'), 'managerFitmentChecked.*' => __('shop.manager_fitment_required'),
            'managerFulfillment.*' => __('shop.manager_receipt_invalid'), 'managerPoint.*' => __('shop.manager_receipt_invalid'),
        ]);
        $cart = [];
        foreach ($this->managerLines as $oldId => $line) {
            if (! isset($this->demoOrder['cart'][$oldId])) {
                throw ValidationException::withMessages(['manager' => __('shop.manager_lines_invalid')]);
            }
            if (! $line['remove']) {
                if (! isset($this->offers()[$line['offer']])) {
                    throw ValidationException::withMessages(['manager' => __('shop.manager_lines_invalid')]);
                }
                $cart[$line['offer']] = ($cart[$line['offer']] ?? 0) + (int) $line['quantity'];
            }
        }
        if (! $cart || array_diff_key($this->demoOrder['cart'], $this->managerLines) || array_filter($cart, fn ($quantity) => $quantity > 99)) {
            throw ValidationException::withMessages(['manager' => __('shop.manager_lines_invalid')]);
        }
        $slot = null;
        if (in_array($this->managerFulfillment, ['installation', 'visit'], true)) {
            $slot = $this->proposalSlotOptions()[$this->managerSlot] ?? null;
            if (! $slot) {
                throw ValidationException::withMessages(['managerSlot' => __('shop.manager_slot_required')]);
            }
        }
        $this->publishProposal('ready', $cart, $this->managerFulfillment, [
            'address' => in_array($this->managerFulfillment, ['delivery', 'visit'], true) ? trim($this->managerAddress) : '',
            'point' => in_array($this->managerFulfillment, ['pickup', 'installation'], true) ? $this->managerPoint : null,
            'slot' => $slot,
        ], $this->scenario);
        $this->showManagerEditor = false;
    }

    public function simulateProposalSlotLoss(int $version): void
    {
        $this->refreshProposalOrder($version);
        abort_unless($this->proposal && $this->proposal['status'] === 'open' && ($this->proposal['slot'] ?? null), 409);
        $this->proposal['slotAvailable'] = false;
        $this->proposalConsent = false;
        $this->persistDemoOrder();
    }
}
