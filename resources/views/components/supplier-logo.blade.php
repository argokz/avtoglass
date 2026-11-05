@props([
    'supplier' => null,
    'name' => null,
    'tier' => null,
    'country' => null,
    'class' => 'h-7 w-auto',
    'showTier' => true,
])

@php
    $supplierName = $supplier?->name ?? $name ?? '';
    $tier = $supplier?->tier ?? $tier ?? 'standard';
    $country = $supplier?->country ?? $country ?? null;

    $normalized = mb_strtolower(trim($supplierName));
    $key = match (true) {
        str_contains($normalized, 'fuyao') || str_contains($normalized, 'fyg') => 'fuyao',
        str_contains($normalized, 'xyg') || str_contains($normalized, 'xinyi') => 'xyg',
        str_contains($normalized, 'agc') || str_contains($normalized, 'asahi') => 'agc',
        str_contains($normalized, 'pilkington') => 'pilkington',
        str_contains($normalized, 'sekurit') || str_contains($normalized, 'saint-gobain') => 'sekurit',
        str_contains($normalized, 'benson') => 'benson',
        str_contains($normalized, 'nordglass') => 'nordglass',
        str_contains($normalized, 'kmk') || str_contains($normalized, 'кмк') => 'kmk',
        str_contains($normalized, 'lemson') || str_contains($normalized, 'лемсон') => 'lemson',
        str_contains($normalized, 'sl') || str_contains($normalized, 'стекло-люкс') => 'sl',
        str_contains($normalized, 'spektr') || str_contains($normalized, 'спектр') => 'spektr',
        str_contains($normalized, 'bor') || str_contains($normalized, 'бор') || str_contains($normalized, 'bgc') => 'bor',
        str_contains($normalized, 'оригинал') || str_contains($normalized, 'oem') => 'oem',
        default => 'generic',
    };

    $tierBadge = match ($tier) {
        'oem' => ['label' => 'OEM Оригинал', 'class' => 'border-ok/20 bg-ok-soft text-ok-ink'],
        'premium' => ['label' => 'Премиум завод', 'class' => 'border-accent/20 bg-accent-soft text-accent-ink'],
        'economy' => ['label' => 'Базовый', 'class' => 'border-warn/20 bg-warn-soft text-warn-ink'],
        default => ['label' => 'Стандарт', 'class' => 'border-line bg-sunken text-muted'],
    };
@endphp

<div class="inline-flex max-w-full flex-wrap items-center gap-2.5">
    {{-- Векторный логотип бренда автостекла --}}
    <div class="flex items-center justify-center shrink-0">
        @switch($key)
            @case('fuyao')
                <div class="flex items-center gap-1 font-sans font-black tracking-tight text-blue-600 dark:text-blue-400">
                    <svg viewBox="0 0 28 20" class="h-5 w-auto" fill="currentColor">
                        <path d="M0 0h6l4 8 4-8h6l-7 14h-6L0 0z" fill="#2563eb" />
                        <circle cx="21" cy="6" r="3.5" fill="#ef4444" />
                    </svg>
                    <span class="text-sm font-extrabold tracking-tight">FUYAO</span>
                </div>
                @break

            @case('xyg')
                <div class="flex items-center gap-1 px-1.5 py-0.5 rounded bg-red-600 text-white font-sans font-black tracking-tighter">
                    <span class="text-xs">XYG</span>
                </div>
                @break

            @case('agc')
                <div class="flex items-center gap-1 font-sans font-extrabold tracking-widest text-sky-600 dark:text-sky-400 border-2 border-sky-600 dark:border-sky-400 px-1.5 py-0.2 rounded">
                    <span class="text-xs">AGC</span>
                </div>
                @break

            @case('pilkington')
                <div class="flex items-center gap-1 font-serif font-black tracking-tight text-emerald-700 dark:text-emerald-400">
                    <svg viewBox="0 0 16 16" class="h-4 w-4" fill="currentColor">
                        <circle cx="8" cy="8" r="7" fill="none" stroke="currentColor" stroke-width="2"/>
                        <circle cx="8" cy="8" r="3" fill="currentColor"/>
                    </svg>
                    <span class="text-xs font-bold font-sans">PILKINGTON</span>
                </div>
                @break

            @case('sekurit')
                <div class="flex items-center gap-1 font-sans font-black text-indigo-600 dark:text-indigo-400">
                    <span class="text-xs tracking-wider">SEKURIT</span>
                </div>
                @break

            @case('benson')
                <div class="flex items-center gap-1 font-sans font-bold text-amber-600 dark:text-amber-400">
                    <svg viewBox="0 0 16 16" class="h-4 w-4" fill="currentColor">
                        <polygon points="8,1 15,8 8,15 1,8" fill="#d97706" />
                    </svg>
                    <span class="text-xs tracking-tight font-extrabold">BENSON</span>
                </div>
                @break

            @case('nordglass')
                <div class="flex items-center gap-1 font-sans font-extrabold text-teal-600 dark:text-teal-400">
                    <span class="text-xs">NORDGLASS</span>
                </div>
                @break

            @case('kmk')
                <div class="flex items-center gap-1 font-sans font-bold text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 px-1.5 py-0.5 rounded">
                    <span class="text-[11px] font-black">КМК GLASS</span>
                </div>
                @break

            @case('lemson')
            @case('sl')
                <div class="flex items-center gap-1 font-sans font-bold text-cyan-600 dark:text-cyan-400">
                    <span class="text-[11px] font-extrabold">LEMSON / SL</span>
                </div>
                @break

            @case('spektr')
                <div class="flex items-center gap-1 font-sans font-bold text-orange-600 dark:text-orange-400">
                    <span class="text-[11px]">СПЕКТР</span>
                </div>
                @break

            @case('bor')
                <div class="flex items-center gap-1 font-sans font-extrabold text-blue-700 dark:text-blue-300">
                    <span class="text-xs">БОР (BGC)</span>
                </div>
                @break

            @case('oem')
                <div class="flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-600 text-white font-sans font-extrabold">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    <span class="text-[10px] tracking-wider uppercase">Оригинал</span>
                </div>
                @break

            @default
                <div class="flex items-center gap-1 font-sans font-semibold text-slate-600 dark:text-slate-400">
                    <span class="text-xs font-bold">{{ $supplierName ?: 'Производитель' }}</span>
                </div>
        @endswitch
    </div>

    {{-- Бейдж класса качества --}}
    @if($showTier)
        <span class="rounded-pill border px-2 py-0.5 text-[10px] font-semibold leading-tight {{ $tierBadge['class'] }}">
            {{ $tierBadge['label'] }}
        </span>
    @endif
</div>
