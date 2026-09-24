<?php

namespace App\Livewire\Preview\Concerns;

use App\Livewire\Preview\PreviewCustomerData;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

trait ManagesCustomerPreferences
{
    #[Url(as: 'section', history: true)]
    public string $accountSection = 'profile';

    public string $profileName = '';

    public string $profileEmail = '';

    public array $addressForm = ['label' => '', 'city' => '', 'line' => '', 'recipient' => '', 'phone' => ''];

    public bool $addressDefault = false;

    #[Locked]
    public ?int $addressEditingId = null;

    #[Locked]
    public ?int $selectedAddressId = null;

    public string $companyName = '';

    public string $companyBin = '';

    #[Locked]
    public bool $customerTermsChanged = false;

    public function customerData(): array
    {
        return $this->signedIn() ? (new PreviewCustomerData)->read() : [];
    }

    private function loadCustomerForms(): void
    {
        $this->reset('profileName', 'profileEmail', 'addressForm', 'addressDefault', 'addressEditingId', 'selectedAddressId', 'companyName', 'companyBin');
        if (! $this->signedIn()) {
            return;
        }
        $data = $this->customerData();
        $this->profileName = $data['profile']['name'];
        $this->profileEmail = $data['profile']['email'];
        $this->companyName = $data['wholesale']['company'];
        $this->companyBin = $data['wholesale']['bin'];
    }

    public function saveCustomerProfile(): void
    {
        $data = (new PreviewCustomerData)->read();
        $this->useLocale();
        $this->profileName = trim($this->profileName);
        $this->profileEmail = trim($this->profileEmail);
        $this->validate(['profileName' => ['required', 'string', 'min:2', 'max:100'], 'profileEmail' => ['nullable', 'email', 'max:190']], [
            'profileName.*' => __('shop.validation_name'), 'profileEmail.*' => __('shop.profile_email_invalid'),
        ]);
        $data['profile'] = ['name' => $this->profileName, 'email' => $this->profileEmail];
        (new PreviewCustomerData)->save($data);
        $this->notice = 'profile_saved';
    }

    public function saveCustomerAddress(): void
    {
        $data = (new PreviewCustomerData)->read();
        $this->useLocale();
        $this->addressForm = array_map(fn ($value) => is_string($value) ? trim($value) : '', $this->addressForm);
        $this->validate([
            'addressForm.label' => ['required', 'string', 'max:60'], 'addressForm.city' => ['required', 'string', 'max:64'],
            'addressForm.line' => ['required', 'string', 'max:255'], 'addressForm.recipient' => ['required', 'string', 'min:2', 'max:100'],
            'addressForm.phone' => ['required', 'regex:/^\+?[0-9()\s-]{10,20}$/'],
        ], ['addressForm.*.*' => __('shop.address_invalid')]);
        if ($this->addressEditingId !== null) {
            abort_unless(isset($data['addresses'][$this->addressEditingId]), 404);
        } elseif (count($data['addresses']) >= 20) {
            throw ValidationException::withMessages(['addressForm.label' => __('shop.address_limit')]);
        }
        $id = $this->addressEditingId ?? ++$data['addressSequence'];
        $data['addresses'][$id] = [...array_intersect_key($this->addressForm, array_flip(['label', 'city', 'line', 'recipient', 'phone'])), 'id' => $id, 'default' => $this->addressDefault];
        if ($this->addressDefault) {
            foreach ($data['addresses'] as $key => &$address) {
                $address['default'] = $key === $id;
            }
            unset($address);
        }
        if (! array_filter($data['addresses'], fn ($address) => $address['default'])) {
            $data['addresses'][array_key_first($data['addresses'])]['default'] = true;
        }
        (new PreviewCustomerData)->save($data);
        $this->clearAddressForm();
        $this->notice = 'address_saved';
    }

    public function editCustomerAddress(int $id): void
    {
        $address = $this->customerData()['addresses'][$id] ?? null;
        abort_unless($address, 404);
        $this->addressEditingId = $id;
        $this->addressDefault = $address['default'];
        $this->addressForm = array_intersect_key($address, array_flip(['label', 'city', 'line', 'recipient', 'phone']));
        $this->resetValidation();
        $this->dispatch('address-form-ready');
    }

    public function clearAddressForm(): void
    {
        $this->reset('addressForm', 'addressDefault', 'addressEditingId');
        $this->resetValidation();
    }

    public function deleteCustomerAddress(int $id): void
    {
        $data = (new PreviewCustomerData)->read();
        abort_unless(isset($data['addresses'][$id]), 404);
        unset($data['addresses'][$id]);
        if ($data['addresses'] && ! array_filter($data['addresses'], fn ($address) => $address['default'])) {
            $data['addresses'][array_key_first($data['addresses'])]['default'] = true;
        }
        (new PreviewCustomerData)->save($data);
        if ($this->addressEditingId === $id) {
            $this->clearAddressForm();
        }
        $this->notice = 'address_deleted';
    }

    public function chooseCustomerAddress(int $id): void
    {
        $data = (new PreviewCustomerData)->read();
        $address = $data['addresses'][$id] ?? null;
        abort_unless($address, 404);
        $this->address = $address['city'].', '.$address['line'];
        $this->name = $address['recipient'];
        $this->phone = $address['phone'];
        $this->selectedAddressId = $id;
        $this->accepted = false;
        $this->saveDraft();
        $this->notice = 'address_chosen';
    }

    public function useCustomerContacts(): void
    {
        $data = (new PreviewCustomerData)->read();
        $this->name = $data['profile']['name'];
        $this->phone = session('modernization.preview_identity');
        $this->saveDraft();
    }

    public function requestWholesaleAccess(): void
    {
        $data = (new PreviewCustomerData)->read();
        $this->useLocale();
        abort_unless(in_array($data['wholesale']['status'], ['none', 'rejected'], true), 409);
        $this->companyName = trim($this->companyName);
        $this->companyBin = trim($this->companyBin);
        $this->validate(['companyName' => ['required', 'string', 'min:2', 'max:190'], 'companyBin' => ['nullable', 'regex:/^\d{12}$/']], [
            'companyName.*' => __('shop.company_invalid'), 'companyBin.*' => __('shop.company_bin_invalid'),
        ]);
        $data['wholesale'] = ['status' => 'pending', 'company' => $this->companyName, 'bin' => $this->companyBin, 'reason' => null];
        (new PreviewCustomerData)->save($data);
        $this->accepted = false;
        $this->notice = 'wholesale_requested';
    }

    public function simulateWholesaleDecision(string $decision): void
    {
        $data = (new PreviewCustomerData)->read();
        abort_unless(in_array($decision, ['approved', 'rejected'], true) && $data['wholesale']['status'] === 'pending', 422);
        $before = array_intersect_key(array_column($this->offers(), 'price', 'id'), $this->cart);
        $data['wholesale']['status'] = $decision;
        $data['wholesale']['reason'] = $decision === 'rejected' ? 'wholesale_rejection_reason' : null;
        (new PreviewCustomerData)->save($data);
        if ($this->mode === 'wholesale' && $this->cart && $decision === 'approved') {
            $this->repeatPrices = $before;
            $this->customerTermsChanged = true;
        }
        $this->accepted = false;
        $this->saveDraft();
        $this->notice = 'wholesale_decision_saved';
    }
}
