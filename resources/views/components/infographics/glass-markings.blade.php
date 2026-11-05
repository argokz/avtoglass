@props([
    'product' => null,
    'class' => '',
])

@php
    $eurocode = $product?->eurocode ?: '7260AGNGYVW1B';
    $oem = $product?->oem_number;
@endphp

<div class="rounded-card border border-line bg-surface p-6 shadow-sm {{ $class }}">
    <div class="flex items-center gap-3">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-soft text-brand-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </span>
        <div>
            <h3 class="text-base font-bold text-ink">Как читать маркировку (штамп) на автостекле</h3>
            <p class="text-xs text-muted">В углу каждого сертифицированного стекла нанесён заводской штамп</p>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[280px_minmax(0,1fr)] lg:items-center">
        {{-- Интерактивный штамп автостекла --}}
        <div class="relative mx-auto w-full max-w-[280px] rounded-2xl border-2 border-dashed border-slate-700 bg-slate-950 p-5 font-mono text-slate-200 shadow-inner">
            <div class="text-center">
                {{-- Логотип / Название завода --}}
                <div class="text-lg font-black tracking-widest text-sky-400 border-b border-slate-800 pb-1">
                    FUYAO // AGC
                </div>
                <div class="mt-1 text-[11px] text-slate-400">LAMINATED // ТРИПЛЕКС</div>
                <div class="mt-0.5 text-[10px] text-emerald-400">AS1 M848 DOT 459</div>

                {{-- Круг омологации E --}}
                <div class="my-2 flex items-center justify-center gap-2">
                    <span class="grid h-10 w-10 place-items-center rounded-full border-2 border-slate-300 text-sm font-black text-white">
                        E1
                    </span>
                    <span class="text-[11px] text-slate-300 font-bold">43R - 00098</span>
                </div>

                {{-- Еврокод и точки даты --}}
                <div class="text-xs font-bold text-amber-300">
                    {{ $eurocode }}
                </div>
                @if($oem)
                    <div class="mt-0.5 text-[10px] text-slate-400">
                        OEM {{ $oem }}
                    </div>
                @endif
                <div class="mt-1 text-sm tracking-[0.3em] text-slate-400">
                    ... 26 ...
                </div>
            </div>
        </div>

        {{-- Расшифровка элементов --}}
        <div class="grid gap-3 text-xs sm:grid-cols-2">
            <div class="rounded-xl border border-line bg-sunken p-3">
                <strong class="flex items-center gap-1.5 font-bold text-sky-600 dark:text-sky-400">
                    <span>1. Производитель (бренд)</span>
                </strong>
                <p class="mt-1 text-muted">Завод-изготовитель стекла (AGC, Fuyao, Pilkington, XYG и т.д.) или фирменный логотип автоконцерна (OEM).</p>
            </div>

            <div class="rounded-xl border border-line bg-sunken p-3">
                <strong class="flex items-center gap-1.5 font-bold text-emerald-600 dark:text-emerald-400">
                    <span>2. Стандарты DOT и AS1</span>
                </strong>
                <p class="mt-1 text-muted">AS1 — ветровое многослойное стекло (триплекс) со светопропусканием не менее 70%. DOT — код лицензии Минтранса США.</p>
            </div>

            <div class="rounded-xl border border-line bg-sunken p-3">
                <strong class="flex items-center gap-1.5 font-bold text-indigo-600 dark:text-indigo-400">
                    <span>3. Омологация ECE R43 (E1)</span>
                </strong>
                <p class="mt-1 text-muted">Знак соответствия евростандарту безопасности. Цифра в круге указывает страну сертификации (E1 — Германия, E2 — Франция, E6 — Бельгия).</p>
            </div>

            <div class="rounded-xl border border-line bg-sunken p-3">
                <strong class="flex items-center gap-1.5 font-bold text-amber-600 dark:text-amber-400">
                    <span>4. Еврокод ({{ $eurocode }})</span>
                </strong>
                <p class="mt-1 text-muted">Международный код однозначно задаёт кузов автомобиля, оттенок, полосу и крепление камеры/датчика дождя.</p>
            </div>
        </div>
    </div>
</div>
