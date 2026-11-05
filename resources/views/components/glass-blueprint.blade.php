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
    $glassTypeLabel = $product?->glass_type?->label() ?? (\App\Enums\GlassType::tryFrom((string) $glassType)?->label() ?? 'Автостекло');
    $w = (int) ($product?->width_mm ?? $width ?? 0);
    $h = (int) ($product?->height_mm ?? $height ?? 0);
    $uid = 'bp_'.($product?->id ?? rand(1000, 9999));

    $hasSunBand = (bool) ($product?->sun_band ?? false);
    $hasCamera = (bool) ($product?->camera ?? false);
    $hasRainSensor = (bool) ($product?->rain_sensor ?? false);
    $hasMirrorBracket = (bool) ($product?->mirror_bracket ?? false);
    $hasHeatedWipers = (bool) ($product?->heated_wipers ?? false);
    $hasHeatedFull = (bool) ($product?->heated_full ?? false);
    $hasHeatedRear = (bool) ($product?->heated_rear ?? false);
    $hasHud = (bool) ($product?->hud ?? false);
    $hasVinWindow = (bool) ($product?->vin_window ?? false);
    $hasAcoustic = (bool) ($product?->acoustic ?? false);
    $hasAtermal = (bool) ($product?->atermal ?? false);
@endphp

<div {{ $attributes->merge(['class' => "relative grid place-items-center overflow-hidden rounded-card bg-night text-on-night select-none $class"]) }}
     style="background: radial-gradient(circle at 50% 50%, var(--color-night-2) 0%, var(--color-night) 100%);">

    {{-- Верхняя плашка: тип стекла и еврокод --}}
    <div class="pointer-events-none absolute top-3 left-3 right-3 z-10 flex flex-wrap items-center justify-between gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-accent/30 bg-night/85 px-2.5 py-1 text-[11px] font-bold tracking-wide text-on-night backdrop-blur-xs">
            <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
            {{ $glassTypeLabel }}
        </span>
        @if($product?->eurocode)
            <span class="rounded border border-accent/40 bg-night/90 px-2 py-0.5 font-mono text-[11px] font-bold text-accent">
                Еврокод: {{ $product->eurocode }}
            </span>
        @endif
    </div>

    {{-- Фоновая инженерная сетка --}}
    <svg class="pointer-events-none absolute inset-0 h-full w-full opacity-15" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <pattern id="{{ $uid }}_grid" width="24" height="24" patternUnits="userSpaceOnUse">
                <path d="M 24 0 L 0 0 0 24" fill="none" stroke="var(--color-accent)" stroke-width="0.75" stroke-opacity="0.5" />
            </pattern>
        </defs>
        <rect width="100%" height="100%" fill="url(#{{ $uid }}_grid)" />
    </svg>

    {{-- Векторный чертёж стекла с размерами и активными зонами опций --}}
    <svg viewBox="0 0 600 360" class="w-full h-full max-h-[320px] p-4 relative z-0" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Схема стекла {{ $glassTypeLabel }}">
        <defs>
            <marker id="{{ $uid }}_arr_s" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
                <path d="M 0 5 L 10 2 L 7 5 L 10 8 z" fill="var(--color-accent)" />
            </marker>
            <marker id="{{ $uid }}_arr_e" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="6" markerHeight="6" orient="auto">
                <path d="M 0 2 L 10 5 L 0 8 L 3 5 z" fill="var(--color-accent)" />
            </marker>
            <linearGradient id="{{ $uid }}_sunband" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#38bdf8" stop-opacity="0.55" />
                <stop offset="100%" stop-color="#38bdf8" stop-opacity="0.05" />
            </linearGradient>
        </defs>

        @switch($glassType)
            {{-- ЛОБОВОЕ СТЕКЛО --}}
            @case('windshield')
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 120 65 Q 300 45 480 65 Q 520 185 540 260 Q 300 285 60 260 Q 80 185 120 65 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="12" stroke-linejoin="round" opacity="0.95" />

                {{-- Солнцезащитная полоса --}}
                @if($hasSunBand)
                    <path d="M 121 67 Q 300 47 479 67 L 489 98 Q 300 80 111 98 Z"
                          fill="url(#{{ $uid }}_sunband)" stroke="#38bdf8" stroke-width="0.8" stroke-opacity="0.5" />
                @endif

                {{-- Полный электрообогрев --}}
                @if($hasHeatedFull)
                    <g stroke="#f59e0b" stroke-width="0.9" stroke-dasharray="3,4" opacity="0.55">
                        @foreach([150, 185, 220, 255, 290, 310, 345, 380, 415, 450] as $hx)
                            <line x1="{{ $hx }}" y1="74" x2="{{ $hx + ($hx < 300 ? -15 : 15) }}" y2="252" />
                        @endforeach
                    </g>
                @endif

                {{-- Камера ADAS / Датчик дождя / Кронштейн зеркала --}}
                @if($hasCamera || $hasRainSensor || $hasMirrorBracket)
                    <g transform="translate(300, 78)">
                        <path d="M -28 -18 L 28 -18 L 22 30 Q 0 34 -22 30 Z"
                              fill="#0f172a" stroke="var(--color-accent)" stroke-width="1.5" />
                        @if($hasCamera)
                            {{-- Трапеция и объектив камеры ADAS --}}
                            <polygon points="-9,-10 9,-10 13,8 -13,8" fill="#1e293b" stroke="#f59e0b" stroke-width="1.2" />
                            <circle cx="0" cy="-1" r="4.5" fill="#f59e0b" />
                            <circle cx="0" cy="-1" r="2" fill="#0f172a" />
                        @endif
                        @if($hasRainSensor)
                            {{-- Оптический датчик дождя и света --}}
                            <circle cx="{{ $hasCamera ? -12 : 0 }}" cy="18" r="5" fill="#0284c7" stroke="#7dd3fc" stroke-width="1.2" />
                            <circle cx="{{ $hasCamera ? 12 : 0 }}" cy="18" r="3" fill="#38bdf8" opacity="0.85" />
                        @endif
                    </g>
                @endif

                {{-- Проекционный дисплей (HUD) --}}
                @if($hasHud)
                    <g transform="translate(155, 168)">
                        <rect x="0" y="0" width="92" height="48" rx="6"
                              fill="rgba(56, 189, 248, 0.12)" stroke="#38bdf8" stroke-width="1.4" stroke-dasharray="5,3" />
                        <text x="46" y="23" fill="#7dd3fc" font-size="11" font-family="monospace" text-anchor="middle" font-weight="800">HUD</text>
                        <text x="46" y="37" fill="#bae6fd" font-size="9" font-family="monospace" text-anchor="middle">проекция</text>
                    </g>
                @endif

                {{-- Обогрев зоны покоя щёток --}}
                @if($hasHeatedWipers)
                    <g stroke="#f59e0b" stroke-width="1.5" opacity="0.9">
                        <path d="M 110 246 Q 300 266 490 246" fill="none" />
                        <path d="M 102 254 Q 300 274 498 254" fill="none" />
                        <line x1="110" y1="245" x2="102" y2="255" />
                        <line x1="490" y1="245" x2="498" y2="255" />
                    </g>
                @endif

                {{-- Окно под VIN-номер --}}
                @if($hasVinWindow)
                    <g transform="translate(105, 224)">
                        <rect x="0" y="0" width="52" height="18" rx="3"
                              fill="#0f172a" stroke="#a7f3d0" stroke-width="1.3" />
                        <text x="26" y="12" fill="#a7f3d0" font-size="9.5" font-family="monospace" text-anchor="middle" font-weight="700">VIN</text>
                    </g>
                @endif
                @break

            {{-- ЗАДНЕЕ СТЕКЛО --}}
            @case('rear')
                <path d="M 130 75 Q 300 60 470 75 Q 510 175 530 250 Q 300 275 70 250 Q 90 175 130 75 Z"
                      fill="color-mix(in oklch, var(--color-accent) 12%, transparent)" stroke="var(--color-accent)" stroke-width="2" stroke-linejoin="round" />
                <path d="M 130 75 Q 300 60 470 75 Q 510 175 530 250 Q 300 275 70 250 Q 90 175 130 75 Z"
                      fill="none" stroke="var(--color-night)" stroke-width="12" stroke-linejoin="round" opacity="0.95" />
                @if($hasHeatedRear || $hasHeatedFull)
                    <g stroke="#f59e0b" stroke-width="1.1" opacity="0.65">
                        @foreach([105, 130, 155, 180, 205, 230] as $ry)
                            <path d="M {{ 125 - ($ry - 100) * 0.25 }} {{ $ry }} Q 300 {{ $ry + 10 }} {{ 475 + ($ry - 100) * 0.25 }} {{ $ry }}" fill="none" />
                        @endforeach
                    </g>
                @endif
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

    {{-- Легенда активных зон на чертеже --}}
    @if($hasCamera || $hasRainSensor || $hasSunBand || $hasHeatedWipers || $hasHeatedFull || $hasHeatedRear || $hasHud || $hasVinWindow || $hasAcoustic || $hasAtermal)
        <div class="pointer-events-none absolute bottom-2 left-3 right-28 z-10 flex flex-wrap gap-1">
            @if($hasCamera)
                <span class="inline-flex items-center gap-1 rounded bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-bold text-amber-300 ring-1 ring-amber-400/40">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span> Камера ADAS
                </span>
            @endif
            @if($hasRainSensor)
                <span class="inline-flex items-center gap-1 rounded bg-sky-500/20 px-1.5 py-0.5 text-[10px] font-bold text-sky-300 ring-1 ring-sky-400/40">
                    <span class="h-1.5 w-1.5 rounded-full bg-sky-400"></span> Датчик дождя
                </span>
            @endif
            @if($hasSunBand)
                <span class="inline-flex items-center gap-1 rounded bg-sky-500/20 px-1.5 py-0.5 text-[10px] font-bold text-sky-200 ring-1 ring-sky-400/30">
                    Полоса
                </span>
            @endif
            @if($hasHeatedWipers || $hasHeatedFull || $hasHeatedRear)
                <span class="inline-flex items-center gap-1 rounded bg-amber-500/20 px-1.5 py-0.5 text-[10px] font-bold text-amber-200 ring-1 ring-amber-400/30">
                    {{ $hasHeatedFull ? 'Полный обогрев' : ($hasHeatedWipers ? 'Обогрев щёток' : 'Обогрев') }}
                </span>
            @endif
            @if($hasHud)
                <span class="inline-flex items-center gap-1 rounded bg-sky-500/20 px-1.5 py-0.5 text-[10px] font-bold text-sky-200 ring-1 ring-sky-400/30">
                    HUD проекция
                </span>
            @endif
            @if($hasVinWindow)
                <span class="inline-flex items-center gap-1 rounded bg-emerald-500/20 px-1.5 py-0.5 text-[10px] font-bold text-emerald-200 ring-1 ring-emerald-400/30">
                    Окно VIN
                </span>
            @endif
            @if($hasAcoustic)
                <span class="inline-flex items-center gap-1 rounded bg-slate-500/30 px-1.5 py-0.5 text-[10px] font-bold text-slate-200 ring-1 ring-slate-400/30">
                    Акустика
                </span>
            @endif
            @if($hasAtermal)
                <span class="inline-flex items-center gap-1 rounded bg-violet-500/20 px-1.5 py-0.5 text-[10px] font-bold text-violet-200 ring-1 ring-violet-400/30">
                    Атермальное
                </span>
            @endif
        </div>
    @endif

    @if($w > 0 && $h > 0)
        <div class="absolute right-3 bottom-2 z-10 rounded border border-accent/40 bg-night/90 px-2 py-0.5 font-mono text-[11px] font-bold text-accent">
            {{ $w }} × {{ $h }} мм
        </div>
    @endif
</div>
