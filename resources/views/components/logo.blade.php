@props([
    'size' => 'md',        // md — шапка, lg — подвал
    'onNight' => false,
])

@php
    $имя = settings()->brandName();
    // Исходные PNG весят по 1,2 МБ, а показываются шириной до 280 css px:
    // браузер берёт WebP 640 px (~25–50 КБ), PNG — только запасной путь.
    $версия = '?v=20261105';
@endphp

<span {{ $attributes->class(['brand-logo', 'brand-logo-lg' => $size === 'lg', 'brand-logo-night' => $onNight]) }}>
    @unless ($onNight)
        <picture>
            <source type="image/webp" srcset="{{ asset('images/logo-light.webp') }}{{ $версия }}">
            <img src="{{ asset('images/logo-light.png') }}{{ $версия }}" alt="{{ $имя }}"
                 width="640" height="360" class="brand-logo-light" decoding="async">
        </picture>
    @endunless
    {{-- В светлой теме тёмный логотип скрыт: lazy не даёт браузеру его скачивать. --}}
    <picture>
        <source type="image/webp" srcset="{{ asset('images/logo.webp') }}{{ $версия }}">
        <img src="{{ asset('images/logo.png') }}{{ $версия }}" alt="{{ $onNight ? $имя : '' }}"
             @unless ($onNight) aria-hidden="true" @endunless
             width="640" height="320" class="brand-logo-dark" loading="lazy" decoding="async">
    </picture>
</span>
