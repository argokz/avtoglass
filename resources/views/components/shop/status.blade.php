@props(['state' => 'review'])
@php
    $tone = match ($state) {
        'available', 'verified', 'confirmed', 'completed' => 'bg-ok-soft text-ok-ink',
        'pending', 'changed', 'review' => 'bg-warn-soft text-warn-ink',
        default => 'bg-sunken text-muted',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-pill px-2.5 py-1 text-xs font-bold', $tone]) }}>
    <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
    {{ __('shop.status_'.$state) }}
</span>
