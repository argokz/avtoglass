@props(['offer', 'selected' => false, 'action', 'disabled' => false, 'compact' => false, 'previous' => null, 'reviewRequired' => false])
<article {{ $attributes->class(['flex min-w-0 flex-col rounded-card border border-line bg-surface p-5 shadow-card']) }} data-offer-id="{{ $offer['id'] }}">
    @unless ($compact)<div class="mb-5 grid h-32 place-items-center rounded-2xl bg-sunken text-line-strong"><x-glass-icon :type="$offer['id'] === 3 ? 'rear' : 'windshield'" class="h-20 w-32" /></div>@endunless
    <div class="flex flex-wrap gap-2"><x-shop.status :state="$offer['stock'] > 0 ? 'available' : 'unavailable'" /><x-shop.status :state="$offer['verified'] && ! $reviewRequired ? 'verified' : 'review'" /></div>
    <h2 class="mt-4 text-lg font-extrabold">{{ __('shop.'.$offer['title']) }}</h2><p class="mt-2 text-sm text-muted">{{ $offer['manufacturer'] }} · {{ $offer['article'] }}</p>
    <x-shop.price :amount="$offer['price']" :previous="$previous" class="mt-5 text-2xl" />
    <button type="button" wire:click="{{ $action }}" class="btn {{ $selected ? 'btn-outline' : 'btn-primary' }} mt-5 min-h-11 w-full" @disabled($disabled) wire:loading.attr="disabled" aria-pressed="{{ $selected ? 'true' : 'false' }}" aria-label="{{ __('shop.choose') }} {{ $offer['article'] }}">{{ $selected ? __('shop.selected') : __('shop.choose') }}</button>
</article>
