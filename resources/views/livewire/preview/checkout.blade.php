@if ($screen === 'chat')<h2 class="text-2xl font-extrabold">{{ $editing ? __('shop.edit') : __('shop.checkout') }}</h2>@else<h1 class="text-3xl font-extrabold">{{ $editing ? __('shop.edit') : __('shop.checkout') }}</h1>@endif
<p class="mt-2 text-muted">{{ $mode === 'wholesale' ? __('shop.wholesale_note') : __('shop.guest_note') }}</p>
@if ($assistantReviewIds)<x-shop.notice tone="warning" class="mt-4">{{ __('shop.chat_fitment_review') }}</x-shop.notice>@endif
@if ($repeatedFrom)<p class="mt-3 text-sm font-bold">{{ __('shop.repeat_source', ['number' => $repeatedFrom]) }}</p>@endif
@if ($unresolvedRows)
    @include('livewire.preview.repeat-missing')
@endif
@if ($editing)
    <x-shop.notice :tone="$this->editDraftConflict() ? 'warning' : 'info'" class="mt-5" :title="__('shop.edit_draft_title', ['number' => $editContext['number'] ?? $demoOrder['number'], 'version' => $editingVersion])">
        {{ __('shop.'.($this->editDraftConflict() ? 'edit_draft_conflict' : 'edit_draft_note')) }}
        <div class="mt-4 flex flex-wrap gap-3"><button type="button" wire:click="detachEditingDraft" class="btn btn-outline min-h-11">{{ __('shop.edit_detach') }}</button>@if ($this->editDraftConflict() && $this->orderEditReason() === null)<button type="button" wire:click="reloadEditingOrder" wire:confirm="{{ __('shop.edit_reload_confirm') }}" class="btn btn-outline min-h-11">{{ __('shop.edit_reload') }}</button>@endif</div>
    </x-shop.notice>
@endif
@if (! $cart && ! $unresolvedRows)
    <x-shop.empty class="mt-6"><button type="button" wire:click="go('catalog')" class="btn btn-primary min-h-11">{{ __('shop.catalog') }}</button></x-shop.empty>
@else
    <form wire:submit="submit" class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]" novalidate>
        <div class="min-w-0 space-y-5">
            <section class="rounded-card border border-line bg-surface p-5 sm:p-6" aria-labelledby="preview-items">
                <h2 id="preview-items" class="text-lg font-extrabold">1. {{ __('shop.items') }}</h2>
                <div class="mt-5 divide-y divide-line">
                    @foreach ($cart as $id => $quantity)
                        @php $offer = $offers[$id]; @endphp
                        <div class="py-4 first:pt-0 last:pb-0" wire:key="preview-cart-{{ $id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div class="min-w-0"><h3 class="font-bold">{{ __('shop.'.$offer['title']) }}</h3><p class="mt-1 text-sm text-muted">{{ $offer['manufacturer'] }} · {{ $offer['article'] }}</p></div><button type="button" wire:click="toggle({{ $id }})" class="min-h-11 px-2 text-sm font-bold text-muted underline underline-offset-4" aria-label="{{ __('shop.remove') }} {{ $offer['article'] }}">{{ __('shop.remove') }}</button></div>
                            <div class="mt-3 flex flex-wrap gap-2"><x-shop.status :state="$offer['stock'] >= $quantity ? 'available' : 'unavailable'" /><x-shop.status :state="$offer['verified'] && ! isset($assistantReviewIds[$id]) ? 'verified' : 'review'" /></div>
                            <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
                                <x-shop.quantity :offer="$offer" wire:model.live.debounce.700ms="cart.{{ $id }}" :error="$errors->first('cart.'.$id)" />
                                @php $oldPrice = $repeatPrices[$id] ?? ($scenario === 'changed' && $id === 1 ? 3500000 : null); @endphp
                                <x-shop.price :amount="$offer['price'] === null || ! is_numeric($quantity) ? null : $offer['price'] * $quantity" :previous="$oldPrice !== null && $oldPrice !== $offer['price'] && is_numeric($quantity) ? $oldPrice * $quantity : null" class="text-lg" />
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
            @if (in_array($scenario, ['unknown_price', 'out_of_stock', 'review', 'changed'], true) || $customerTermsChanged)
                <x-shop.notice tone="warning" :title="__('shop.'.($customerTermsChanged ? 'customer_terms_changed' : $scenario))">
                    {{ $customerTermsChanged ? __('shop.customer_terms_note') : __('shop.'.match ($scenario) { 'unknown_price' => 'unknown_note', 'out_of_stock' => 'stock_note', 'changed' => 'changed_note', default => 'review_note' }) }}
                    @if ($scenario === 'changed' || $customerTermsChanged)<label class="mt-4 flex min-h-11 items-center gap-3 font-bold"><input type="checkbox" wire:model.live="accepted" class="h-5 w-5 shrink-0 accent-brand">{{ __('shop.accept_changes') }}</label>@endif
                </x-shop.notice>
            @endif
            <section class="rounded-card border border-line bg-surface p-5 sm:p-6">
                <h2 class="text-lg font-extrabold">2. {{ __('shop.delivery') }}</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach (['pickup' => 'pickup', 'delivery' => 'shipping', ...($mode === 'retail' ? ['installation' => 'installation', 'visit' => 'visit'] : [])] as $value => $label)
                        <label @class(['flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border px-4 py-3', 'border-brand bg-brand-soft' => $fulfillment === $value, 'border-line' => $fulfillment !== $value])><input type="radio" wire:model.live="fulfillment" value="{{ $value }}" name="fulfillment" class="h-5 w-5 accent-brand"><span class="font-semibold">{{ __('shop.'.$label) }}</span></label>
                    @endforeach
                </div>
                @if (in_array($fulfillment, ['delivery', 'visit'], true))
                    <div class="mt-5"><x-shop.field name="preview-address" :label="__('shop.address')" wire:model.live.debounce.700ms="address" autocomplete="street-address" :error="$errors->first('address')" /></div>
                    @if ($this->signedIn() && $this->customerData()['addresses'])<details class="mt-4 rounded-xl border border-line p-4"><summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">{{ __('shop.address_choose') }}</summary><div class="mt-3 space-y-3">@foreach ($this->customerData()['addresses'] as $savedAddress)<button type="button" wire:click="chooseCustomerAddress({{ $savedAddress['id'] }})" class="block min-h-11 w-full rounded-xl border border-line p-3 text-left" aria-label="{{ __('shop.address_choose_named', ['name' => $savedAddress['label']]) }}"><span class="block font-semibold">{{ $savedAddress['label'] }} @if ($savedAddress['default'])· {{ __('shop.address_default') }}@endif</span><span class="mt-2 block break-words text-sm text-muted">{{ $savedAddress['city'] }}, {{ $savedAddress['line'] }}</span></button>@endforeach</div></details>@endif
                @else
                    <p class="mt-5 text-sm font-bold">{{ __('shop.point') }}</p><p class="mt-1 text-muted">{{ __('shop.point_value') }}</p>
                @endif
                @if (in_array($fulfillment, ['installation', 'visit'], true))<p class="mt-4 text-sm font-bold">{{ __('shop.slot') }}</p><p class="mt-1 text-muted">{{ __('shop.slot_value') }}</p>@endif
            </section>
            <section class="rounded-card border border-line bg-surface p-5 sm:p-6">
                <h2 class="text-lg font-extrabold">3. {{ __('shop.contacts') }}</h2>
                @if ($this->signedIn())<button type="button" wire:click="useCustomerContacts" class="mt-3 min-h-11 text-sm font-bold underline">{{ __('shop.profile_use_contacts') }}</button>@endif
                <div class="mt-5 grid gap-5 sm:grid-cols-2"><x-shop.field name="preview-name" :label="__('shop.name')" wire:model.live.debounce.700ms="name" autocomplete="name" :placeholder="__('shop.name_hint')" :error="$errors->first('name')" /><x-shop.field name="preview-phone" :label="__('shop.phone')" type="tel" wire:model.live.debounce.700ms="phone" autocomplete="tel" :placeholder="__('shop.phone_hint')" :error="$errors->first('phone')" /></div>
            </section>
            <section class="rounded-card border border-line bg-surface p-5 sm:p-6"><h2 class="text-lg font-extrabold">4. {{ __('shop.payment') }}</h2><p class="mt-3 text-muted">{{ $needsReview ? __('shop.payment_review') : __('shop.pay_later') }}</p></section>
        </div>
        <div class="space-y-4 lg:sticky lg:top-6">
            <x-shop.summary :quote="$quote" :live="true">
                <label class="flex min-h-11 items-start gap-3 text-sm leading-relaxed"><input type="checkbox" wire:model="consent" class="mt-1 h-5 w-5 shrink-0 accent-brand" @error('consent') aria-invalid="true" aria-describedby="preview-consent-error" @enderror><span>{{ __('shop.consent') }}</span></label>
                @error('consent')<p id="preview-consent-error" class="mt-2 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
                @error('checkout')<p data-checkout-error tabindex="-1" class="mt-3 text-sm font-bold text-warn-ink" role="alert">{{ $message }}</p>@enderror
                <x-shop.action-bar :quote="$quote" class="mt-5"><button type="submit" class="btn btn-primary min-h-12 w-full" wire:loading.attr="disabled" wire:target="submit">{{ $editing ? __('shop.save_changes') : ($needsReview ? __('shop.send_review') : __('shop.confirm')) }}</button></x-shop.action-bar>
                <p class="mt-3 text-xs text-muted">{{ __('shop.prototype_note') }}</p>
            </x-shop.summary>
        </div>
    </form>
@endif
