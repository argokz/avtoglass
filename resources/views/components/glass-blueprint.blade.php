@props([
    'product' => null,
    'type' => null,
    'width' => null,
    'height' => null,
    'showDimensions' => true,
    'class' => 'w-full h-full min-h-[220px]',
])

@php
    $glassType = $product?->glass_type?->value ?? ($type instanceof \App\Enums\GlassType ? $type->value : ($type ?? 'windshield'));
    $w = (int) ($product?->width_mm ?? $width ?? 0);
    $h = (int) ($product?->height_mm ?? $height ?? 0);
    $uid = 'bp_'.($product?->id ?? rand(1000, 9999));
@endphp

<div {{ $attributes->merge(['class' => "relative grid place-items-center overflow-hidden rounded-card bg-night text-on-night select-none $class"]) }}
     style="background: radial-gradient(circle at 50% 50%, var(--color-night-2) 0%, var(--color-night) 100%);">

    {{-- Фоновая инженерная сетка --}}
    <svg class="pointer-events-none absolute inset-0 h-full w-full opacity-15" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <pattern id="{{ $uid }}_grid" width="24" height="24" patternUnits="userSpaceOnUse">
                <path d="M 24 0 L 0 0 0 24" fill="none" stroke="var(--color-accent)" stroke-width="0.75" stroke-opacity="0.5" />
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#{{ $uid }}_grid)" />
    </svg>

    {{-- Векторный чертёж стекла с размерами --}}
    <svg viewBox="0 0 600 360" class="w-full h-full max-h-[320px] p-4 relative z-0" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <marker id="{{ $uid }}_arr_s" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                <path d="M 0 5 L 10 2 L 7 5 L 10 8 z" fill="var(--color-accent)" />
            </marker>
            <marker id="{{ $uid }}_arr_e" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto">
                <path d="M 0 2 L 10 5 L 0 8 L 3 5 z" fill="var(--color-accent)" />
            </marker>
        </defs>

        @switch($glassType)
            {{-- ЛОБОВОЕ СТЕКЛО --}}
            @case('windshield')
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="12" stroke-linejoin="round" opacity="0.95" />
                @break

            {{-- ЗАДНЕЕ СТЕКЛО --}}
            @case('rear')
                <path d="M 130 75 Q 300 60 470 75 Q 510 175 530 250 Q 300 275 70 250 Q 90 175 130 75 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 130 75 Q 300 60 470 75 Q 510 175 530 250 Q 300 275 70 250 Q 90 175 130 75 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="12" stroke-linejoin="round" opacity="0.95" />
                @break

            {{-- БОКОВОЕ СТЕКЛО --}}
            @case('side')
                <path d="M 130 255 L 130 135 Q 130 95 170 90 L 370 70 Q 430 65 470 135 L 470 255 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 130 255 L 130 135 Q 130 95 170 90 L 370 70 Q 430 65 470 135 L 470 255 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="10" stroke-linejoin="round" opacity="0.95" />
                @break

            {{-- ФОРТОЧКА --}}
            @case('quarter')
                <path d="M 160 255 L 160 105 L 440 255 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 160 255 L 160 105 L 440 255 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="10" stroke-linejoin="round" opacity="0.95" />
                @break

            {{-- КРЫША / ПАНОРАМА --}}
            @case('roof')
                <rect x="130" y="75" width="340" height="185" rx="16"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" />
                <rect x="130" y="75" width="340" height="185" rx="16"
                      fill="none" stroke="var(--color-night)" stroke-width="14" opacity="0.95" />
                @break

            @default
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="12" stroke-linejoin="round" opacity="0.95" />
        @endswitch

        {{-- РАЗМЕРНЫЕ ЛИНИИ --}}
        @if($showDimensions)
            {{-- Ширина (W) --}}
            <g>
                <line x1="55" y1="265" x2="55" y2="320" stroke="var(--color-accent)" stroke-width="0.8" stroke-dasharray="3,3" opacity="0.6" />
                <line x1="545" y1="265" x2="545" y2="320" stroke="var(--color-accent)" stroke-width="0.8" stroke-dasharray="3,3" opacity="0.6" />
                <line x1="60" y1="310" x2="540" y2="310" stroke="var(--color-accent)" stroke-width="1.3"
                      marker-start="url(#{{ $uid }}_arr_s)" marker-end="url(#{{ $uid }}_arr_e)" />
                <rect x="240" y="298" width="120" height="24" rx="4" fill="var(--color-night)" stroke="var(--color-accent)" stroke-width="1.2" />
                <text x="300" y="314" fill="var(--color-accent)" font-size="12" font-family="monospace" text-anchor="middle" font-weight="700">
                    {{ $w > 0 ? "Ширина: {$w} мм" : 'Ширина (W)' }}
                </text>
            </g>

            {{-- Высота (H) --}}
            <g>
                <line x1="485" y1="60" x2="585" y2="60" stroke="var(--color-accent)" stroke-width="0.8" stroke-dasharray="3,3" opacity="0.6" />
                <line x1="545" y1="260" x2="585" y2="260" stroke="var(--color-accent)" stroke-width="0.8" stroke-dasharray="3,3" opacity="0.6" />
                <line x1="575" y1="65" x2="575" y2="255" stroke="var(--color-accent)" stroke-width="1.3"
                      marker-start="url(#{{ $uid }}_arr_s)" marker-end="url(#{{ $uid }}_arr_e)" />
                <g transform="translate(575, 160)">
                    <rect x="-55" y="-12" width="110" height="24" rx="4" fill="var(--color-night)" stroke="var(--color-accent)" stroke-width="1.2" />
                    <text x="0" y="4" fill="var(--color-accent)" font-size="11" font-family="monospace" text-anchor="middle" font-weight="700">
                        {{ $h > 0 ? "Высота: {$h} мм" : 'Высота (H)' }}
                    </text>
                </g>
            </g>
        @endif
    </svg>

    @if($w > 0 && $h > 0)
        <div class="absolute right-3 bottom-2 z-10 rounded border border-accent/40 bg-night/90 px-2 py-0.5 font-mono text-[11px] font-bold text-accent">
            {{ $w }} × {{ $h }} мм
        </div>
    @endif
</div>
