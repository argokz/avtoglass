@props([
    'path' => null,
    'alt' => '',
    'class' => '',
    'eager' => false,
    'sizes' => '100vw',
])

@php
    $variants = app(\App\Support\ImageVariants::class)->for($path);
@endphp

@if ($variants)
    <picture>
        @if ($variants['srcset'] !== '')
            <source type="image/webp" srcset="{{ $variants['srcset'] }}" sizes="{{ $sizes }}">
        @endif
        <img src="{{ $variants['fallback'] }}" alt="{{ $alt }}"
             width="{{ $variants['width'] }}" height="{{ $variants['height'] }}"
             @if ($eager) fetchpriority="high" @else loading="lazy" @endif
             decoding="async"
             {{ $attributes->merge(['class' => $class]) }}>
    </picture>
@endif
