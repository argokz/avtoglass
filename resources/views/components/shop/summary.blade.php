@props(['quote', 'live' => false])
<section {{ $attributes->class(['rounded-card border border-line bg-surface p-5 shadow-card']) }} aria-label="{{ __('shop.summary') }}">
    <h2 class="text-lg font-extrabold">{{ __('shop.summary') }}</h2>
    <p class="mt-1 text-sm text-muted">{{ __('shop.positions', ['count' => $quote['positions']]) }} · {{ __('shop.units', ['count' => $quote['units']]) }}</p>
    <dl class="mt-5 space-y-3 text-sm">
        <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('shop.goods') }}</dt><dd>@if ($quote['invalid'] ?? false)<span class="text-warn-ink">{{ __('shop.fix_quantity') }}</span>@else<x-shop.price :amount="$quote['goods']" />@endif</dd></div>
        @if ($quote['services'] > 0)
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('shop.services') }}</dt><dd><x-shop.price :amount="$quote['services']" /></dd></div>
        @endif
        @if ($quote['delivery'] > 0)
            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('shop.shipping_fee') }}</dt><dd><x-shop.price :amount="$quote['delivery']" /></dd></div>
        @endif
        <div class="flex flex-wrap justify-between gap-2 border-t border-line pt-4 text-base font-bold"><dt>{{ __('shop.total') }}</dt><dd data-quote-total>@if ($quote['invalid'] ?? false)<span class="text-sm text-warn-ink">{{ __('shop.fix_quantity') }}</span>@else<x-shop.price :amount="$quote['total']" />@endif</dd></div>
    </dl>
    @if ($live)
        <div class="text-sm text-warn-ink" aria-live="polite">
            <span wire:dirty wire:target="cart,fulfillment">{{ __('shop.quote_updating') }}</span>
            <span wire:loading wire:target="cart,fulfillment,toggle">{{ __('shop.quote_updating') }}</span>
        </div>
    @endif
    @if ($quote['unknown'] > 0)
        <p class="mt-3 text-sm text-muted">{{ __('shop.unknown_count', ['count' => $quote['unknown']]) }}</p>
        <p class="mt-2 text-sm">{{ __('shop.known_total') }}: <x-shop.price :amount="$quote['known']" /></p>
    @endif
    @if ($slot->isNotEmpty())<div class="mt-5">{{ $slot }}</div>@endif
</section>
