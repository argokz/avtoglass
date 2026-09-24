@props([
    'brand' => null,
    'name' => null,
    'slug' => null,
    'class' => 'h-8 w-8',
])

@php
    $brandName = $brand?->name ?? $name ?? '';
    $brandSlug = $brand?->slug ?? $slug ?? Str::slug($brandName);
    $normalized = mb_strtolower(trim($brandSlug));

    // Определение ключа марки
    $key = match (true) {
        str_contains($normalized, 'toyota') => 'toyota',
        str_contains($normalized, 'lexus') => 'lexus',
        str_contains($normalized, 'mercedes') || str_contains($normalized, 'benz') => 'mercedes',
        str_contains($normalized, 'bmw') => 'bmw',
        str_contains($normalized, 'audi') => 'audi',
        str_contains($normalized, 'volkswagen') || $normalized === 'vw' => 'volkswagen',
        str_contains($normalized, 'hyundai') => 'hyundai',
        str_contains($normalized, 'kia') => 'kia',
        str_contains($normalized, 'nissan') => 'nissan',
        str_contains($normalized, 'honda') => 'honda',
        str_contains($normalized, 'mazda') => 'mazda',
        str_contains($normalized, 'mitsubishi') => 'mitsubishi',
        str_contains($normalized, 'subaru') => 'subaru',
        str_contains($normalized, 'chevrolet') || str_contains($normalized, 'chevy') => 'chevrolet',
        str_contains($normalized, 'lada') || str_contains($normalized, 'vaz') || str_contains($normalized, 'ваз') => 'lada',
        str_contains($normalized, 'ford') => 'ford',
        str_contains($normalized, 'renault') => 'renault',
        str_contains($normalized, 'skoda') => 'skoda',
        str_contains($normalized, 'land-rover') || str_contains($normalized, 'range-rover') => 'land-rover',
        str_contains($normalized, 'porsche') => 'porsche',
        str_contains($normalized, 'chery') => 'chery',
        str_contains($normalized, 'haval') => 'haval',
        str_contains($normalized, 'geely') => 'geely',
        str_contains($normalized, 'changan') => 'changan',
        str_contains($normalized, 'byd') => 'byd',
        str_contains($normalized, 'zeekr') => 'zeekr',
        str_contains($normalized, 'li-auto') || str_contains($normalized, 'lixiang') => 'lixiang',
        str_contains($normalized, 'exeed') => 'exeed',
        str_contains($normalized, 'jetour') => 'jetour',
        str_contains($normalized, 'tank') => 'tank',
        default => 'generic',
    };
@endphp

<div {{ $attributes->merge(['class' => "inline-flex items-center justify-center $class"]) }} title="{{ $brandName }}">
    @switch($key)
        {{-- TOYOTA --}}
        @case('toyota')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="6">
                <ellipse cx="50" cy="35" rx="45" ry="30" />
                <ellipse cx="50" cy="24" rx="26" ry="12" />
                <ellipse cx="50" cy="35" rx="13" ry="29" />
            </svg>
            @break

        {{-- LEXUS --}}
        @case('lexus')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="6">
                <ellipse cx="50" cy="35" rx="44" ry="28" />
                <path d="M 68 20 L 32 20 L 32 50 L 68 50" stroke-linecap="round" stroke-linejoin="round" stroke-width="7" />
                <path d="M 68 20 L 35 48" stroke-linecap="round" stroke-width="7" />
            </svg>
            @break

        {{-- MERCEDES-BENZ --}}
        @case('mercedes')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <circle cx="50" cy="50" r="44" />
                <polygon points="50,10 54,48 50,50 46,48" fill="currentColor" />
                <polygon points="15,70 48,54 50,50 46,48" fill="currentColor" />
                <polygon points="85,70 54,48 50,50 52,54" fill="currentColor" />
            </svg>
            @break

        {{-- BMW --}}
        @case('bmw')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none">
                <circle cx="50" cy="50" r="46" stroke="currentColor" stroke-width="5" />
                <circle cx="50" cy="50" r="34" stroke="currentColor" stroke-width="2" />
                <path d="M 50 16 A 34 34 0 0 1 84 50 L 50 50 Z" fill="#0066b1" />
                <path d="M 16 50 A 34 34 0 0 1 50 16 L 50 50 Z" fill="#ffffff" />
                <path d="M 50 50 L 16 50 A 34 34 0 0 0 50 84 Z" fill="#0066b1" />
                <path d="M 50 50 L 50 84 A 34 34 0 0 0 84 50 Z" fill="#ffffff" />
            </svg>
            @break

        {{-- AUDI --}}
        @case('audi')
            <svg viewBox="0 0 140 50" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="4.5">
                <circle cx="28" cy="25" r="20" />
                <circle cx="56" cy="25" r="20" />
                <circle cx="84" cy="25" r="20" />
                <circle cx="112" cy="25" r="20" />
            </svg>
            @break

        {{-- VOLKSWAGEN --}}
        @case('volkswagen')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <circle cx="50" cy="50" r="44" />
                <circle cx="50" cy="50" r="38" stroke-width="2" />
                <path d="M 26 30 L 40 68 L 50 42 L 60 68 L 74 30" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.5" />
                <path d="M 33 60 L 50 82 L 67 60" stroke-linecap="round" stroke-linejoin="round" stroke-width="4.5" />
            </svg>
            @break

        {{-- HYUNDAI --}}
        @case('hyundai')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="6">
                <ellipse cx="50" cy="35" rx="44" ry="28" transform="rotate(-15 50 35)" />
                <path d="M 36 20 C 38 32 38 48 34 50 M 64 20 C 60 22 60 38 62 50 M 36 35 C 44 33 56 33 62 35"
                      stroke-linecap="round" stroke-width="7" transform="rotate(-10 50 35)" />
            </svg>
            @break

        {{-- KIA --}}
        @case('kia')
            <svg viewBox="0 0 100 50" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M 18 12 L 18 38 M 32 12 L 18 25 L 32 38" />
                <path d="M 44 12 L 44 38 M 44 12 L 56 12 L 56 38" />
                <path d="M 68 38 L 78 12 L 88 38 M 71 30 L 85 30" />
            </svg>
            @break

        {{-- NISSAN --}}
        @case('nissan')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <circle cx="50" cy="50" r="42" />
                <rect x="10" y="40" width="80" height="20" fill="currentColor" stroke="none" />
                <text x="50" y="55" fill="#ffffff" font-size="12" font-family="sans-serif" font-weight="900" text-anchor="middle" letter-spacing="1">NISSAN</text>
            </svg>
            @break

        {{-- HONDA --}}
        @case('honda')
            <svg viewBox="0 0 100 90" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="6">
                <rect x="8" y="8" width="84" height="74" rx="14" />
                <path d="M 28 22 L 32 68 M 72 22 L 68 68 M 30 42 L 70 42" stroke-linecap="round" stroke-width="7" />
            </svg>
            @break

        {{-- MAZDA --}}
        @case('mazda')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <ellipse cx="50" cy="35" rx="44" ry="28" />
                <path d="M 20 40 Q 50 15 80 40 Q 50 30 20 40 Z" fill="currentColor" stroke="none" />
                <path d="M 32 40 L 50 25 L 68 40" stroke-width="4.5" stroke-linecap="round" />
            </svg>
            @break

        {{-- MITSUBISHI --}}
        @case('mitsubishi')
            <svg viewBox="0 0 100 100" class="w-full h-full text-red-600" fill="currentColor">
                <polygon points="50,15 62,35 50,55 38,35" />
                <polygon points="50,55 62,75 38,75" />
                <polygon points="62,35 86,75 62,75" />
                <polygon points="38,35 14,75 38,75" />
            </svg>
            @break

        {{-- SUBARU --}}
        @case('subaru')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <ellipse cx="50" cy="35" rx="44" ry="28" />
                <circle cx="62" cy="28" r="7" fill="currentColor" stroke="none" />
                <circle cx="44" cy="24" r="4.5" fill="currentColor" stroke="none" />
                <circle cx="34" cy="34" r="4" fill="currentColor" stroke="none" />
                <circle cx="38" cy="45" r="4" fill="currentColor" stroke="none" />
                <circle cx="52" cy="46" r="4.5" fill="currentColor" stroke="none" />
                <circle cx="68" cy="42" r="4" fill="currentColor" stroke="none" />
            </svg>
            @break

        {{-- CHEVROLET --}}
        @case('chevrolet')
            <svg viewBox="0 0 100 60" class="w-full h-full text-amber-500" fill="currentColor">
                <polygon points="10,22 90,22 86,38 6,38" />
                <polygon points="36,8 64,8 64,52 36,52" />
            </svg>
            @break

        {{-- LADA --}}
        @case('lada')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <ellipse cx="50" cy="50" rx="42" ry="32" />
                <path d="M 38 65 Q 50 25 62 65 Q 50 58 38 65 Z" fill="currentColor" stroke="none" />
                <path d="M 50 32 L 50 62" stroke-width="3" stroke="#fff" />
            </svg>
            @break

        {{-- CHERY --}}
        @case('chery')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <ellipse cx="50" cy="35" rx="44" ry="26" />
                <path d="M 30 45 L 50 20 L 70 45" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="50" cy="32" r="7" fill="currentColor" stroke="none" />
            </svg>
            @break

        {{-- HAVAL --}}
        @case('haval')
            <div class="px-2 py-0.5 rounded bg-red-600 text-white font-sans font-black text-[11px] tracking-widest uppercase">
                HAVAL
            </div>
            @break

        {{-- GEELY --}}
        @case('geely')
            <svg viewBox="0 0 100 70" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="4">
                <rect x="15" y="15" width="70" height="40" rx="6" />
                <line x1="15" y1="35" x2="85" y2="35" />
                <line x1="38" y1="15" x2="38" y2="55" />
                <line x1="62" y1="15" x2="62" y2="55" />
            </svg>
            @break

        {{-- CHANGAN --}}
        @case('changan')
            <svg viewBox="0 0 100 100" class="w-full h-full text-current" fill="none" stroke="currentColor" stroke-width="5">
                <circle cx="50" cy="50" r="42" />
                <path d="M 30 35 Q 50 75 70 35" stroke-width="7" stroke-linecap="round" />
            </svg>
            @break

        {{-- BYD --}}
        @case('byd')
            <div class="px-2 py-0.5 rounded border-2 border-current text-current font-sans font-black text-[11px] tracking-widest">
                BYD
            </div>
            @break

        {{-- ZEEKR --}}
        @case('zeekr')
            <div class="rounded border border-line-strong bg-night px-2 py-0.5 font-sans text-[10px] font-black tracking-widest text-on-night uppercase">
                ZEEKR
            </div>
            @break

        {{-- DEFAULT / GENERIC --}}
        @default
            <div class="flex h-full w-full items-center justify-center rounded-xl bg-sunken font-bold text-xs uppercase text-muted border border-line">
                {{ mb_substr($brandName, 0, 2) }}
            </div>
    @endswitch
</div>
