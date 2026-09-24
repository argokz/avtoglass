@props(['class' => ''])

<div class="rounded-card border border-line bg-surface p-6 shadow-sm {{ $class }}">
    <div class="flex items-center gap-3">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-soft text-accent-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
            </svg>
        </span>
        <div>
            <h3 class="text-base font-bold text-ink">Анатомия 17-значного VIN-кода</h3>
            <p class="text-xs text-muted">Как устроен номер кузова и что именно мы из него считываем</p>
        </div>
    </div>

    {{-- Схема 17 знаков --}}
    <div class="mt-6">
        <div class="grid grid-cols-4 gap-2 text-center text-xs sm:grid-cols-4 font-mono">
            <div class="rounded-xl border-2 border-accent/40 bg-accent-soft p-3">
                <div class="text-lg font-black text-accent-ink">W M I</div>
                <div class="text-[10px] text-faint">Знаки 1 – 3</div>
                <div class="mt-1 font-bold text-ink">Завод и Марка</div>
            </div>

            <div class="rounded-xl border border-line bg-sunken p-3">
                <div class="text-lg font-bold text-muted">V D S</div>
                <div class="text-[10px] text-faint">Знаки 4 – 8</div>
                <div class="mt-1 text-muted">Кузов / Модель</div>
            </div>

            <div class="rounded-xl border-2 border-warn/40 bg-warn-soft p-3">
                <div class="text-lg font-black text-warn-ink">Y E A R</div>
                <div class="text-[10px] text-faint">Знак 10</div>
                <div class="mt-1 font-bold text-ink">Год выпуска</div>
            </div>

            <div class="rounded-xl border border-line bg-sunken p-3">
                <div class="text-lg font-bold text-muted">V I S</div>
                <div class="text-[10px] text-faint">Знаки 11 – 17</div>
                <div class="mt-1 text-muted">Серийный номер</div>
            </div>
        </div>

        <div class="mt-4 rounded-xl border border-line bg-sunken p-4 text-xs text-muted">
            <p>
                💡 <strong class="font-semibold text-ink">Где найти VIN на вашей машине:</strong> в техпаспорте (СРТС), в левом нижнем углу лобового стекла под специальным окошком шелкографии, либо на наклейке/шильдике в проёме водительской двери.
            </p>
        </div>
    </div>
</div>
