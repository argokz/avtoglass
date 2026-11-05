@props([
    'size' => 'md',        // md — шапка, lg — подвал
    'onNight' => false,
])

@php
    $имя = settings()->brandName();
@endphp

<span {{ $attributes->class(['brand-logo', 'brand-logo-lg' => $size === 'lg', 'brand-logo-night' => $onNight]) }}>
    @unless ($onNight)
        <img src="{{ asset('images/logo-light.png') }}?v=20261008" alt="{{ $имя }}"
             width="1672" height="941" class="brand-logo-light" decoding="async">
    @endunless
    <img src="{{ asset('images/logo.png') }}?v=20261008" alt="{{ $onNight ? $имя : '' }}"
         @unless ($onNight) aria-hidden="true" @endunless
         width="1774" height="887" class="brand-logo-dark" decoding="async">
</span>
