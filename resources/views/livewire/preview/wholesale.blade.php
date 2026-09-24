<div class="flex flex-wrap items-start justify-between gap-4">
    <div><h1 class="text-3xl font-extrabold">{{ __('shop.wholesale') }}</h1><p class="mt-3 text-muted">{{ __('shop.wholesale_note') }}</p></div>
    <div class="flex flex-wrap gap-3"><button type="button" wire:click="$toggle('showList')" class="btn btn-outline min-h-11">{{ __('shop.paste') }}</button><button type="button" wire:click="$set('showList', true)" class="btn btn-outline min-h-11">{{ __('shop.file_upload') }}</button></div>
</div>
@if ($showList)
    @include('livewire.preview.import-review')
@endif
<div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]">
    <section class="min-w-0">
        <x-shop.field name="preview-search" :label="__('shop.search')" type="search" wire:model.live.debounce.350ms="search" />
        <div class="mt-4 overflow-hidden rounded-card border border-line bg-surface">
            <div class="hidden grid-cols-[minmax(0,2fr)_1fr_6rem_1fr] gap-3 border-b border-line bg-sunken px-5 py-3 text-xs font-bold text-muted md:grid"><span>{{ __('shop.items') }}</span><span>{{ __('shop.unit_price') }}</span><span>{{ __('shop.quantity') }}</span><span class="text-right">{{ __('shop.line_total') }}</span></div>
            @php $visible = array_filter($offers, fn ($offer) => $search === '' || str_contains(mb_strtolower($offer['article'].' '.__('shop.'.$offer['title'])), mb_strtolower($search))); @endphp
            @forelse ($visible as $offer)
                <div class="grid items-center gap-4 border-b border-line p-5 last:border-b-0 md:grid-cols-[minmax(0,2fr)_1fr_6rem_1fr]" wire:key="preview-wholesale-{{ $offer['id'] }}">
                    <label class="flex min-h-11 cursor-pointer items-start gap-3"><input type="checkbox" @checked(isset($cart[$offer['id']])) wire:click="toggle({{ $offer['id'] }})" class="mt-1 h-5 w-5 shrink-0 accent-brand" aria-label="{{ __('shop.choose') }} {{ $offer['article'] }}"><span class="min-w-0"><span class="block font-bold">{{ __('shop.'.$offer['title']) }}</span><span class="mt-1 block text-sm text-muted">{{ $offer['article'] }} · {{ $offer['manufacturer'] }}</span><span class="mt-2 block"><x-shop.status :state="$offer['stock'] > 0 ? 'available' : 'unavailable'" /></span></span></label>
                    <div><span class="mr-2 text-sm text-muted md:sr-only">{{ __('shop.unit_price') }}</span><x-shop.price :amount="$offer['price']" /></div>
                    @if (isset($cart[$offer['id']]))<x-shop.quantity :offer="$offer" wire:model.live.debounce.700ms="cart.{{ $offer['id'] }}" :error="$errors->first('cart.'.$offer['id'])" />@else<span class="text-muted" aria-hidden="true">—</span>@endif
                    <div class="md:text-right">@if (isset($cart[$offer['id']]))<span class="sr-only">{{ __('shop.line_total') }}: </span><x-shop.price :amount="$offer['price'] === null || ! is_numeric($cart[$offer['id']]) ? null : $offer['price'] * $cart[$offer['id']]" />@endif</div>
                </div>
            @empty
                <x-shop.empty :title="__('shop.filter_empty')" description="" class="!border-0" />
            @endforelse
        </div>
    </section>
    <x-shop.summary :quote="$quote" :live="true" class="lg:sticky lg:top-6"><p class="text-sm text-muted">{{ __('shop.wholesale_note') }}</p>@if ($this->hasPendingImport())<p class="mt-3 text-sm text-warn-ink">{{ __('shop.validation_import') }}</p>@endif<x-shop.action-bar :quote="$quote" class="mt-5"><button type="button" wire:click="go('checkout')" class="btn btn-primary min-h-12 w-full" @disabled(! $cart || $this->hasPendingImport() || $quote['invalid'])>{{ __('shop.continue') }}</button></x-shop.action-bar></x-shop.summary>
</div>
