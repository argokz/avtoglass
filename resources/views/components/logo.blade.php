@props([
    'size' => 'md',        // md — шапка, lg — подвал
    'onNight' => false,
])

@php
    $высота = $size === 'lg' ? 'h-12 sm:h-16' : 'h-10 sm:h-12';
    $имя = settings()->brandName();
@endphp

<span {{ $attributes->merge(['class' => 'flex min-w-0 items-center']) }}>
    {{--
        Знак без фона: белая машина, красная рамка, слово на белой полосе.
        Плашку не кладём — чёрный прямоугольник был артефактом исходного JPEG.
        На светлой шапке лёгкая тень держит белый силуэт; в подвале она не нужна.
    --}}
    <img src="{{ asset('images/logo.png') }}"
         alt="{{ $имя }}"
         width="902" height="330"
         @class([
             $высота,
             'w-auto max-w-[13.5rem] object-contain object-left sm:max-w-[18rem]',
             'drop-shadow-[0_1px_1px_rgba(15,18,24,0.22)]' => ! $onNight,
         ])>
</span>
