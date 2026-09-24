@if (! $this->signedIn())
    @include('livewire.preview.cabinet-login')
@else
    @php $customer = $this->customerData(); $section = in_array($accountSection, ['profile', 'addresses', 'templates', 'access'], true) ? $accountSection : 'profile'; @endphp
    <div class="flex flex-wrap items-start justify-between gap-4"><h1 class="text-3xl font-extrabold">{{ __('shop.account') }}</h1><button type="button" wire:click="logoutCabinet" class="btn btn-outline min-h-11">{{ __('shop.logout') }}</button></div>
    <nav class="mt-6 flex flex-wrap gap-3" aria-label="{{ __('shop.account_sections') }}">
        @foreach (['profile', 'addresses', 'templates', 'access'] as $tab)<button type="button" wire:click="$set('accountSection', '{{ $tab }}')" class="btn {{ $section === $tab ? 'btn-primary' : 'btn-outline' }} min-h-11" aria-pressed="{{ $section === $tab ? 'true' : 'false' }}">{{ __('shop.account_'.$tab) }}</button>@endforeach
    </nav>
    @if ($section === 'profile')
        <section class="mt-6 max-w-2xl rounded-card border border-line bg-surface p-5 sm:p-6">
            <h2 class="text-xl font-extrabold">{{ __('shop.account_profile') }}</h2>
            <p class="mt-4 font-semibold">{{ __('shop.profile_confirmed_phone') }}: {{ session('modernization.preview_identity') }}</p><p class="mt-2 text-sm text-muted">{{ __('shop.profile_phone_note') }}</p>
            <form wire:submit="saveCustomerProfile" class="mt-6 space-y-5">
                <x-shop.field name="profile-name" :label="__('shop.name')" wire:model="profileName" autocomplete="name" :error="$errors->first('profileName')" />
                <x-shop.field name="profile-email" :label="__('shop.profile_email')" type="email" wire:model="profileEmail" autocomplete="email" :error="$errors->first('profileEmail')" :hint="__('shop.profile_email_note')" />
                <button type="submit" class="btn btn-primary min-h-11" wire:loading.attr="disabled">{{ __('shop.profile_save') }}</button>
            </form>
        </section>
    @elseif ($section === 'addresses')
        <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
            <section class="rounded-card border border-line bg-surface p-5 sm:p-6"><h2 id="address-form-title" tabindex="-1" class="text-xl font-extrabold">{{ $addressEditingId ? __('shop.address_edit') : __('shop.address_add') }}</h2><p class="mt-3 text-sm text-muted">{{ __('shop.address_snapshot_note') }}</p>
                <form wire:submit="saveCustomerAddress" class="mt-5 space-y-4">
                    @foreach (['label' => 'address_label', 'city' => 'address_city', 'line' => 'address', 'recipient' => 'address_recipient', 'phone' => 'phone'] as $field => $label)<x-shop.field name="address-{{ $field }}" :label="__('shop.'.$label)" :type="$field === 'phone' ? 'tel' : 'text'" wire:model="addressForm.{{ $field }}" :error="$errors->first('addressForm.'.$field)" />@endforeach
                    <label class="flex min-h-11 items-center gap-3 text-sm font-semibold"><input type="checkbox" wire:model="addressDefault" class="h-5 w-5 shrink-0 accent-brand">{{ __('shop.address_default') }}</label>
                    <div class="flex flex-wrap gap-3"><button type="submit" class="btn btn-primary min-h-11" wire:loading.attr="disabled">{{ __('shop.address_save') }}</button>@if ($addressEditingId)<button type="button" wire:click="clearAddressForm" class="btn btn-outline min-h-11">{{ __('shop.cancel_edit') }}</button>@endif</div>
                </form>
            </section>
            <section class="min-w-0 space-y-4" aria-label="{{ __('shop.account_addresses') }}">
                @forelse ($customer['addresses'] as $address)<article class="rounded-card border border-line bg-surface p-5" wire:key="customer-address-{{ $address['id'] }}" data-customer-address="{{ $address['id'] }}"><h2 class="break-words text-lg font-extrabold">{{ $address['label'] }}</h2>@if ($address['default'])<p class="mt-2 text-sm font-bold text-brand-ink">{{ __('shop.address_default') }}</p>@endif<p class="mt-3 break-words">{{ $address['city'] }}, {{ $address['line'] }}</p><p class="mt-2 break-words text-sm text-muted">{{ $address['recipient'] }} · {{ $address['phone'] }}</p><div class="mt-4 flex flex-wrap gap-3"><button type="button" wire:click="editCustomerAddress({{ $address['id'] }})" class="btn btn-outline min-h-11">{{ __('shop.address_edit') }}</button><button type="button" wire:click="deleteCustomerAddress({{ $address['id'] }})" wire:confirm="{{ __('shop.address_delete_confirm') }}" class="min-h-11 text-sm font-semibold underline">{{ __('shop.delete') }}</button></div></article>@empty<x-shop.empty :title="__('shop.address_empty')" />@endforelse
            </section>
        </div>
    @elseif ($section === 'templates')
        <section class="mt-6 rounded-card border border-line bg-surface p-5 sm:p-6"><h2 class="text-xl font-extrabold">{{ __('shop.account_templates') }}</h2><p class="mt-3 text-muted">{{ __('shop.template_note') }}</p>
            <form wire:submit="savePurchaseTemplate" class="mt-5 grid gap-4"><div class="min-w-0 max-w-xl"><x-shop.field name="template-name" :label="__('shop.template_name')" wire:model="templateName" :error="$errors->first('templateName')" /></div><div class="flex flex-wrap gap-3"><button type="submit" class="btn btn-primary min-h-11" wire:loading.attr="disabled">{{ $templateEditingId ? __('shop.template_rename_save') : __('shop.template_save') }}</button>@if ($templateEditingId)<button type="button" wire:click="cancelTemplateRename" class="btn btn-outline min-h-11">{{ __('shop.cancel_edit') }}</button>@endif</div></form>
            <p class="mt-4 text-sm text-muted">{{ __('shop.template_current', ['count' => $mode === 'wholesale' ? count($cart) + count($unresolvedRows) : 0]) }}</p><button type="button" wire:click="go('wholesale')" class="mt-2 min-h-11 text-sm font-bold underline">{{ __('shop.template_prepare') }}</button>
        </section>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
            @forelse ($customer['templates'] as $template)<article class="rounded-card border border-line bg-surface p-5" wire:key="purchase-template-{{ $template['id'] }}" data-purchase-template="{{ $template['id'] }}"><h2 class="break-words text-lg font-extrabold">{{ $template['name'] }}</h2><p class="mt-3 text-sm text-muted">{{ __('shop.positions', ['count' => count($template['items'])]) }} · {{ __('shop.units', ['count' => array_sum(array_column($template['items'], 'quantity'))]) }}</p><ul class="mt-3 space-y-2 text-sm">@foreach ($template['items'] as $item)<li>{{ $item['article'] }} · {{ $item['manufacturer'] }} · {{ $item['quantity'] }}</li>@endforeach</ul><div class="mt-5 flex flex-wrap gap-3"><button type="button" wire:click="applyPurchaseTemplate({{ $template['id'] }})" class="btn btn-primary min-h-11">{{ __('shop.template_apply') }}</button><button type="button" wire:click="renamePurchaseTemplate({{ $template['id'] }})" class="btn btn-outline min-h-11">{{ __('shop.rename') }}</button><button type="button" wire:click="deletePurchaseTemplate({{ $template['id'] }})" wire:confirm="{{ __('shop.template_delete_confirm') }}" class="min-h-11 text-sm font-semibold underline">{{ __('shop.delete') }}</button></div></article>@empty<x-shop.empty :title="__('shop.template_empty')" />@endforelse
        </div>
    @else
        @php $access = $customer['wholesale']; @endphp
        <section class="mt-6 max-w-2xl rounded-card border border-line bg-surface p-5 sm:p-6"><h2 class="text-xl font-extrabold">{{ __('shop.account_access') }}</h2><p class="mt-4 font-bold" data-wholesale-access>{{ __('shop.wholesale_access_'.$access['status']) }}</p><p class="mt-3 text-muted">{{ __('shop.wholesale_access_note') }}</p>
            @if ($access['status'] === 'approved')<x-shop.notice class="mt-5">{{ __('shop.wholesale_demo_terms') }}</x-shop.notice>@endif
            @if ($access['reason'])<x-shop.notice tone="warning" class="mt-5">{{ __('shop.'.$access['reason']) }}</x-shop.notice>@endif
            @if (in_array($access['status'], ['none', 'rejected'], true))<form wire:submit="requestWholesaleAccess" class="mt-6 space-y-5"><x-shop.field name="company-name" :label="__('shop.company_name')" wire:model="companyName" :error="$errors->first('companyName')" /><x-shop.field name="company-bin" :label="__('shop.company_bin')" wire:model="companyBin" inputmode="numeric" :error="$errors->first('companyBin')" /><button type="submit" class="btn btn-primary min-h-11" wire:loading.attr="disabled">{{ __('shop.wholesale_request') }}</button></form>@else<p class="mt-5 break-words font-semibold">{{ $access['company'] }}</p><p class="mt-2 text-sm text-muted">{{ $access['bin'] }}</p>@endif
            @if ($access['status'] === 'pending')<details class="mt-6 rounded-xl border border-dashed border-line-strong p-4"><summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">{{ __('shop.scenario') }}</summary><div class="mt-3 flex flex-wrap gap-3"><button type="button" wire:click="simulateWholesaleDecision('approved')" class="btn btn-outline min-h-11">{{ __('shop.wholesale_demo_approve') }}</button><button type="button" wire:click="simulateWholesaleDecision('rejected')" class="btn btn-outline min-h-11">{{ __('shop.wholesale_demo_reject') }}</button></div></details>@endif
        </section>
    @endif
@endif
