<dl class="mt-4 space-y-2 text-sm" data-receipt-snapshot>
    <div><dt class="font-bold">{{ __('shop.delivery') }}</dt><dd class="mt-1">{{ __('shop.'.($receipt['fulfillment'] === 'delivery' ? 'shipping' : $receipt['fulfillment'])) }}</dd></div>
    @if (in_array($receipt['fulfillment'], ['delivery', 'visit'], true))<div><dt class="font-bold">{{ __('shop.address') }}</dt><dd class="mt-1 break-words">{{ $receipt['address'] ?? '—' }}</dd></div>@endif
    @if (in_array($receipt['fulfillment'], ['pickup', 'installation'], true))<div><dt class="font-bold">{{ __('shop.manager_point') }}</dt><dd class="mt-1">{{ __('shop.manager_demo_point') }}</dd></div>@endif
    @if ($receipt['slot'] ?? null)<div><dt class="font-bold">{{ __('shop.manager_slot') }}</dt><dd class="mt-1">{{ \Carbon\CarbonImmutable::parse($receipt['slot']['start'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}–{{ \Carbon\CarbonImmutable::parse($receipt['slot']['end'])->timezone(config('app.timezone'))->format('H:i') }} ({{ config('app.timezone') }})</dd></div>@endif
</dl>
