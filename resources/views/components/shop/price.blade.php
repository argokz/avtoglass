@props(['amount' => null, 'previous' => null])
<span {{ $attributes->class(['inline-flex flex-wrap items-baseline gap-2 tabular-nums']) }}>
    @if ($amount === null)
        <span class="text-sm font-semibold text-muted">{{ __('shop.unknown_price') }}</span>
    @else
        @if ($previous !== null && $previous !== $amount)
            <span class="text-sm text-muted"><span class="sr-only">{{ __('shop.previous_price') }}: </span><s>{{ number_format($previous / 100, $previous % 100 ? 2 : 0, ',', ' ') }} ₸</s></span>
        @endif
        <span class="font-extrabold">{{ number_format($amount / 100, $amount % 100 ? 2 : 0, ',', ' ') }} ₸</span>
    @endif
</span>
