@props(['quote'])
<div {{ $attributes->class(['purchase-action-bar']) }} data-purchase-action>
    <div class="purchase-action-total text-sm lg:hidden">
        <span>{{ __('shop.total') }} · {{ __('shop.units', ['count' => $quote['units']]) }}</span>
        @if ($quote['invalid'] ?? false)<span class="font-bold text-warn-ink">{{ __('shop.fix_quantity') }}</span>@else<x-shop.price :amount="$quote['total']" class="font-bold" />@endif
        <span wire:dirty wire:target="cart,fulfillment" class="text-xs text-warn-ink">{{ __('shop.quote_updating') }}</span>
        <span wire:loading wire:target="cart,fulfillment,toggle" class="text-xs text-warn-ink">{{ __('shop.quote_updating') }}</span>
    </div>
    {{ $slot }}
</div>
