@props(['type' => null, 'class' => 'h-full w-full'])

@php
    // Силуэт под тип стекла. Пока нет фотографий, покупатель хотя бы с одного
    // взгляда отличает лобовое от бокового, а не видит одну и ту же заглушку.
    $value = $type instanceof \App\Enums\GlassType ? $type->value : $type;
@endphp

<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 64 48" fill="none"
     stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true">
    @switch($value)
        @case('windshield')
            <path d="M10 34 L17 14 h30 l7 20 z" />
            <path d="M17 14 L22 34 M47 14 L42 34" opacity=".35" />
            <circle cx="32" cy="19" r="2.4" opacity=".55" />
            @break

        @case('rear')
            <path d="M13 32 L19 16 h26 l6 16 z" />
            <path d="M17 29 h30 M18 25 h28 M19 21 h26" opacity=".4" stroke-width="1.1" />
            @break

        @case('side')
            <path d="M12 32 V18 l10-6 h20 l8 8 v12 z" />
            <path d="M22 12 v20" opacity=".35" />
            @break

        @case('quarter')
            <path d="M18 32 L26 14 h14 l6 18 z" />
            <path d="M26 14 L32 32" opacity=".35" />
            @break

        @case('roof')
            <rect x="12" y="12" width="40" height="24" rx="3" />
            <rect x="19" y="18" width="26" height="12" rx="2" opacity=".4" />
            @break

        @case('consumable')
            <path d="M20 34 V16 a4 4 0 0 1 4-4 h16 a4 4 0 0 1 4 4 v18 z" />
            <path d="M20 22 h24" opacity=".4" />
            <path d="M28 12 V8 h8 v4" opacity=".6" />
            @break

        @case('service')
            <path d="M40 14a7 7 0 0 0-9.5 8.6L16 37l4 4 14.4-14.5A7 7 0 0 0 43 17z" />
            @break

        @default
            <path d="M10 34 L17 14 h30 l7 20 z" />
    @endswitch
</svg>
