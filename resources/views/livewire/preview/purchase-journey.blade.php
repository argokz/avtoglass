<div x-data x-effect="document.documentElement.lang = $wire.renderedLocale" x-on:proposal-ready.window="$nextTick(() => document.getElementById('proposal-title')?.focus())" x-on:manager-editor-ready.window="$nextTick(() => document.getElementById('manager-editor-title')?.focus())" x-on:login-code-ready.window="$nextTick(() => document.getElementById('login-code')?.focus())" x-on:history-ready.window="$nextTick(() => document.getElementById('history-title')?.focus())" x-on:address-form-ready.window="$nextTick(() => document.getElementById('address-form-title')?.focus())" x-on:template-name-ready.window="$nextTick(() => document.getElementById('template-name')?.focus())" class="purchase-prototype min-h-screen">
    <fieldset disabled x-bind:disabled="false" class="min-w-0">
        <a href="#preview-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-xl focus:bg-surface focus:p-4">{{ __('shop.skip_content') }}</a>
        <div class="bg-night px-4 py-3 text-on-night">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 text-sm">
                <div><p class="font-bold">{{ __('shop.prototype') }}</p><p class="mt-1 text-on-night-muted">{{ __('shop.prototype_note') }}</p></div>
                <button wire:click="restart" type="button" class="min-h-11 rounded-pill border border-white/30 px-4 font-semibold">{{ __('shop.prototype_reset') }}</button>
            </div>
        </div>
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4">
                <a href="{{ route('home') }}" aria-label="AvtoGlass"><x-logo /></a>
                <div class="flex flex-wrap gap-3"><x-shop.theme-switch /><label class="flex items-center gap-2 text-sm font-semibold">{{ __('shop.language') }}
                    <select wire:model.live="locale" class="select !w-auto !min-h-11 !rounded-pill !py-2"><option value="ru">Русский</option><option value="kk">Қазақша</option></select>
                </label></div>
            </div>
            <nav aria-label="{{ __('shop.prototype') }}" class="mx-auto grid max-w-7xl grid-cols-2 gap-2 px-4 pb-4 sm:flex sm:flex-wrap">
                @foreach (['catalog', 'chat', 'checkout', 'wholesale', 'orders', 'drafts', 'account'] as $page)
                    <button type="button" wire:click="go('{{ $page }}')" @if ($screen === $page || ($page === 'orders' && $screen === 'order')) aria-current="page" @endif
                            @class(['min-h-11 rounded-pill px-4 py-2 text-sm font-bold', 'bg-brand text-on-brand' => $screen === $page || ($page === 'orders' && $screen === 'order'), 'bg-sunken text-muted hover:text-ink' => $screen !== $page && ! ($page === 'orders' && $screen === 'order')])>
                        {{ __('shop.'.$page) }}
                        @if ($page === 'checkout' && count($cart))<span class="ml-1">{{ $quote['units'] }}</span>@endif
                    </button>
                @endforeach
            </nav>
        </header>
        <main id="preview-content" tabindex="-1" class="mx-auto max-w-7xl px-4 py-6 sm:py-8">
            @if ($errors->any())
                <section data-validation-summary tabindex="-1" role="alert" class="mb-5 rounded-xl border border-warn-ink bg-warn-soft p-4 text-warn-ink">
                    <h2 class="font-bold">{{ __('shop.errors_title') }}</h2><p class="mt-2 text-sm">{{ __('shop.errors_note') }}</p>
                    <button type="button" data-focus-error class="mt-2 min-h-11 font-bold underline">{{ __('shop.focus_error') }}</button>
                </section>
            @endif
            <div wire:offline class="mb-5"><x-shop.notice tone="warning" role="status">{{ __('shop.offline') }}</x-shop.notice></div>
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-line-strong p-3">
                <label class="flex flex-wrap items-center gap-3 text-sm text-muted">{{ __('shop.scenario') }}
                    <select wire:model.live="scenario" class="select !w-auto !min-h-11 !py-2">
                        @foreach (\App\Livewire\Preview\PurchaseJourney::SCENARIOS as $state)<option value="{{ $state }}">{{ __('shop.'.$state) }}</option>@endforeach
                    </select>
                </label>
                <div class="text-sm" role="status" aria-live="polite">
                    <span wire:loading wire:target="cart,fulfillment,name,phone,address,locale,scenario,toggle,retrySave">{{ __('shop.saving') }}</span>
                    <span wire:loading.remove wire:target="cart,fulfillment,name,phone,address,locale,scenario,toggle,retrySave">
                        <span wire:dirty wire:target="cart,fulfillment,name,phone,address" class="text-muted">{{ __('shop.unsaved') }}</span>
                        <span wire:dirty.remove wire:target="cart,fulfillment,name,phone,address" @class(['font-semibold', 'text-ok-ink' => $saveState === 'saved', 'text-warn-ink' => $saveState !== 'saved'])>{{ __('shop.'.match ($saveState) { 'saved' => 'saved', 'invalid' => 'fix_quantity', default => 'save_error' }) }}</span>
                    </span>
                </div>
            </div>
            @if ($saveState === 'error')
                <x-shop.notice tone="warning" class="mb-5">
                    {{ __('shop.save_failed') }}<button type="button" wire:click="retrySave" class="btn btn-outline mt-3 min-h-11">{{ __('shop.retry') }}</button>
                </x-shop.notice>
            @endif
            @if ($notice)<x-shop.notice tone="success" class="mb-5" role="status">{{ __('shop.'.$notice) }}</x-shop.notice>@endif
            @if ($screen === 'chat')
                @include('livewire.preview.assistant')
            @elseif ($screen === 'catalog')
                <div class="mb-6"><p class="text-sm font-bold text-brand-ink">{{ __('shop.car') }}</p><h1 class="mt-2 text-3xl font-extrabold">{{ __('shop.choose_glass') }}</h1><p class="mt-3 max-w-2xl text-muted">{{ __('shop.fitment_note') }}</p></div>
                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($offers as $offer)
                        <x-shop.offer-card :offer="$offer" :selected="isset($cart[$offer['id']])" :review-required="isset($assistantReviewIds[$offer['id']])" :action="'toggle('.$offer['id'].')'" :previous="$scenario === 'changed' && $offer['id'] === 1 ? 3500000 : null" wire:key="preview-offer-{{ $offer['id'] }}" />
                    @endforeach
                </div>
                @if ($cart)<x-shop.action-bar :quote="$quote" class="mt-6"><button type="button" wire:click="go('checkout')" class="btn btn-primary min-h-11 w-full lg:w-auto">{{ __('shop.continue_checkout') }}</button></x-shop.action-bar>@endif
            @elseif ($screen === 'checkout')
                @include('livewire.preview.checkout')
            @elseif ($screen === 'wholesale')
                @include('livewire.preview.wholesale')
            @elseif ($screen === 'orders')
                @include('livewire.preview.order-history')
            @elseif ($screen === 'account')
                @include('livewire.preview.customer-account')
            @elseif ($screen === 'order')
                @include('livewire.preview.orders')
            @elseif ($screen === 'drafts')
                <h1 class="text-3xl font-extrabold">{{ __('shop.drafts') }}</h1>
                @php $hasDrafts = (bool) $savedDrafts; @endphp
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($drafts as $draftMode => $selection)
                        @php $details = $draftDetails[$draftMode] ?? []; $missing = $details['unresolved'] ?? []; $hasReview = $this->draftHasReview($details); $hasDrafts = $hasDrafts || (bool) $selection || (bool) $missing || $hasReview; @endphp
                        @if ($selection || $missing || $hasReview)<section class="rounded-card border border-line bg-surface p-5" data-active-draft="{{ $draftMode }}"><h2 class="text-lg font-extrabold">{{ __('shop.'.$draftMode) }}</h2><p class="mt-2 text-muted">{{ __('shop.positions', ['count' => count($selection) + count($missing)]) }} · {{ __('shop.units', ['count' => array_sum($selection) + array_sum(array_column($missing, 'quantity'))]) }}</p>@if ($missing)<p class="mt-2 text-sm text-warn-ink">{{ __('shop.repeat_missing_title') }}</p>@endif @if ($hasReview)<p class="mt-2 text-sm text-muted">{{ __('shop.draft_review_saved') }}</p>@endif @if ($details['edit'] ?? null)<p class="mt-2 text-sm text-muted">{{ __('shop.edit_draft_title', ['number' => $details['edit']['number'], 'version' => $details['edit']['version']]) }}</p>@endif<button type="button" wire:click="resumeMode('{{ $draftMode }}')" class="btn btn-primary mt-5 min-h-11">{{ __('shop.resume') }}</button></section>@endif
                    @endforeach
                    @foreach ($savedDrafts as $index => $draft)
                        <section class="rounded-card border border-line bg-surface p-5" data-saved-draft="{{ $index }}"><h2 class="text-lg font-extrabold">{{ __('shop.saved_selection') }} {{ $index + 1 }}</h2><p class="mt-2 text-muted">{{ __('shop.'.$draft['mode']) }} · {{ __('shop.units', ['count' => array_sum($draft['cart']) + array_sum(array_column($draft['details']['unresolved'] ?? [], 'quantity'))]) }}</p>@if ($draft['details']['unresolved'] ?? [])<p class="mt-2 text-sm text-warn-ink">{{ __('shop.repeat_missing_title') }}</p>@endif @if ($this->draftHasReview($draft['details'] ?? []))<p class="mt-2 text-sm text-muted">{{ __('shop.draft_review_saved') }}</p>@endif<button type="button" wire:click="resumeDraft({{ $index }})" class="btn btn-outline mt-5 min-h-11">{{ __('shop.resume') }}</button></section>
                    @endforeach
                </div>
                @if (! $hasDrafts)<x-shop.empty class="mt-6"><button type="button" wire:click="go('catalog')" class="btn btn-primary min-h-11">{{ __('shop.catalog') }}</button></x-shop.empty>@endif
            @elseif ($screen === 'result')
                @php $resultState = match ($demoOrder['status']) { 'confirmed' => 'confirmed', 'new' => 'updated', default => 'pending' }; @endphp
                <section class="mx-auto max-w-2xl rounded-card border border-line bg-surface p-6 text-center shadow-card sm:p-10">
                    <x-shop.status :state="$demoOrder['status']" />
                    <h1 class="mt-5 text-3xl font-extrabold">{{ __('shop.result_'.$resultState) }}</h1>
                    <p class="mt-4 font-bold">{{ __('shop.order_number', ['number' => $demoOrder['number']]) }}</p>
                    <p class="mt-4 leading-relaxed text-muted">{{ __('shop.result_'.$resultState.'_note') }}</p>
                    <x-shop.price :amount="$demoOrder['total']" class="mt-5 text-2xl" />
                    <div class="mt-8 flex flex-wrap justify-center gap-3"><button type="button" wire:click="go('order')" class="btn btn-primary min-h-11">{{ __('shop.view_order') }}</button><button type="button" wire:click="go('catalog')" class="btn btn-outline min-h-11">{{ __('shop.new_selection') }}</button></div>
                </section>
            @endif
        </main>
    </fieldset>
</div>
