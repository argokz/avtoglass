@props([
    'model' => null,
    'brand' => null,
    'name' => null,
])

@php
    $modelName = $model?->name ?? $name ?? '';
    $brandModel = $model?->brand ?? $brand;
    $brandName = $brandModel?->name ?? '';
    $photo = $model?->imageUrl();
    $logo = $brandModel?->logoUrl();
    $normalized = mb_strtolower($modelName);
    $extra = (string) ($attributes->get('class') ?? '');
    $hasHeight = (bool) preg_match('/(?:^|\s)!?(?:sm:|md:|lg:|xl:)?h-/', $extra);
    $hasWidth = (bool) preg_match('/(?:^|\s)!?(?:sm:|md:|lg:|xl:)?w-/', $extra);

    $bodyType = match (true) {
        str_contains($normalized, 'cruiser') || str_contains($normalized, 'patrol') || str_contains($normalized, 'tahoe') || str_contains($normalized, 'prado') || str_contains($normalized, 'pajero') => 'suv',
        str_contains($normalized, 'rav4') || str_contains($normalized, 'tucson') || str_contains($normalized, 'sportage') || str_contains($normalized, 'qashqai') || str_contains($normalized, 'duster') || str_contains($normalized, 'tiggo') || str_contains($normalized, 'coolray') || str_contains($normalized, 'jolion') || str_contains($normalized, 'rx') || str_contains($normalized, 'x5') || str_contains($normalized, 'x6') || str_contains($normalized, 'gle') || str_contains($normalized, 'glc') => 'crossover',
        str_contains($normalized, 'golf') || str_contains($normalized, 'rio') || str_contains($normalized, 'yaris') || str_contains($normalized, 'i30') || str_contains($normalized, 'cee\'d') || str_contains($normalized, 'matiz') || str_contains($normalized, 'sandero') => 'hatchback',
        str_contains($normalized, 'alphard') || str_contains($normalized, 'vito') || str_contains($normalized, 'caravelle') || str_contains($normalized, 'starex') || str_contains($normalized, 'carnival') || str_contains($normalized, 'v-class') => 'minivan',
        str_contains($normalized, 'hilux') || str_contains($normalized, 'l200') || str_contains($normalized, 'amarok') || str_contains($normalized, 'f-150') || str_contains($normalized, 'ram') => 'pickup',
        default => 'sedan',
    };
@endphp

<div {{ $attributes->class([
    'relative grid place-items-center overflow-hidden rounded-2xl bg-sunken p-3 text-muted',
    'h-32' => ! $hasHeight,
    'w-full' => ! $hasWidth,
]) }}>
    @if ($photo)
        <img src="{{ $photo }}" alt="{{ trim($brandName.' '.$modelName) }}"
             class="h-full w-full object-contain" loading="lazy" decoding="async">
    @else
        <svg viewBox="0 0 160 80" class="h-full w-full" fill="none" stroke="currentColor"
             stroke-linejoin="round" stroke-linecap="round" aria-hidden="true">
            @switch($bodyType)
                @case('suv')
                    <path d="M 15 55 L 20 40 L 40 38 L 55 20 L 115 20 L 135 38 L 150 42 L 150 55 L 140 55 A 12 12 0 0 1 116 55 L 50 55 A 12 12 0 0 1 26 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 58 24 L 85 24 L 85 36 L 43 36 Z M 90 24 L 112 24 L 128 36 L 90 36 Z"
                          class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="38" cy="55" r="10" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="128" cy="55" r="10" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    @break

                @case('crossover')
                    <path d="M 15 55 L 22 42 L 45 40 L 62 24 L 110 24 L 130 40 L 148 44 L 148 55 L 138 55 A 12 12 0 0 1 114 55 L 52 55 A 12 12 0 0 1 28 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 65 28 L 88 28 L 88 38 L 48 38 Z M 92 28 L 108 28 L 124 38 L 92 38 Z"
                          class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="40" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="126" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    @break

                @case('minivan')
                    <path d="M 12 55 L 14 36 L 45 22 L 135 22 L 146 38 L 148 55 L 138 55 A 12 12 0 0 1 114 55 L 50 55 A 12 12 0 0 1 26 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 46 26 L 85 26 L 85 36 L 28 36 Z M 90 26 L 130 26 L 130 36 L 90 36 Z"
                          class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="38" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="126" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    @break

                @case('hatchback')
                    <path d="M 18 55 L 22 45 L 42 42 L 62 26 L 106 26 L 128 42 L 144 46 L 144 55 L 134 55 A 12 12 0 0 1 110 55 L 50 55 A 12 12 0 0 1 26 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 64 30 L 86 30 L 86 40 L 46 40 Z M 90 30 L 104 30 L 120 40 L 90 40 Z"
                          class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="38" cy="55" r="9" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="122" cy="55" r="9" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    @break

                @case('pickup')
                    <path d="M 12 55 L 18 42 L 48 40 L 62 22 L 100 22 L 112 40 L 150 42 L 150 55 L 140 55 A 12 12 0 0 1 116 55 L 50 55 A 12 12 0 0 1 26 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 64 26 L 86 26 L 86 38 L 50 38 Z" class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="38" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="128" cy="55" r="9.5" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    @break

                @default
                    <path d="M 12 55 L 16 46 L 38 44 L 58 26 L 106 26 L 124 44 L 148 46 L 148 55 L 136 55 A 12 12 0 0 1 112 55 L 50 55 A 12 12 0 0 1 26 55 Z"
                          fill="currentColor" fill-opacity="0.14" stroke-width="3" />
                    <path d="M 60 30 L 84 30 L 84 42 L 44 42 Z M 88 30 L 104 30 L 118 42 L 88 42 Z"
                          class="fill-accent stroke-accent" fill-opacity="0.35" stroke-width="1.5" />
                    <circle cx="38" cy="55" r="9" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
                    <circle cx="124" cy="55" r="9" fill="currentColor" fill-opacity="0.25" stroke="currentColor" stroke-width="3" />
            @endswitch
        </svg>

        @if ($logo)
            <img src="{{ $logo }}" alt=""
                 class="absolute top-2 left-2 h-8 w-8 rounded-lg bg-surface/90 object-contain p-1 shadow-sm"
                 loading="lazy" decoding="async">
        @elseif ($brandName !== '')
            <span class="absolute top-2 left-2 rounded-lg bg-surface/90 px-1.5 py-0.5 text-[10px] font-extrabold tracking-wide uppercase text-ink">
                {{ mb_substr($brandName, 0, 3) }}
            </span>
        @endif
    @endif
</div>
