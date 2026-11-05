@props(['class' => ''])

<div class="rounded-card border border-line bg-surface p-6 shadow-sm {{ $class }}">
    <div class="flex items-center gap-3">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-ok-soft text-ok-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </span>
        <div>
            <h3 class="text-base font-bold text-ink">5 этапов профессиональной замены автостекла</h3>
            <p class="text-xs text-muted">Работа занимает от 60 до 90 минут с соблюдением заводских стандартов</p>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        {{-- Шаг 1 --}}
        <div class="relative rounded-2xl border border-line bg-sunken p-4">
            <div class="flex items-center justify-between">
                <span class="grid h-7 w-7 place-items-center rounded-full bg-brand font-display text-xs font-black text-on-brand">1</span>
                <span class="text-[10px] font-semibold text-faint">10 мин</span>
            </div>
            <h4 class="mt-3 text-sm font-bold text-ink">Подбор и осмотр</h4>
            <p class="mt-1 text-xs text-muted">Сверка датчиков, VIN и шелкографии. Защитные чехлы на сиденья, руль и крылья.</p>
        </div>

        {{-- Шаг 2 --}}
        <div class="relative rounded-2xl border border-line bg-sunken p-4">
            <div class="flex items-center justify-between">
                <span class="grid h-7 w-7 place-items-center rounded-full bg-brand font-display text-xs font-black text-on-brand">2</span>
                <span class="text-[10px] font-semibold text-faint">20 мин</span>
            </div>
            <h4 class="mt-3 text-sm font-bold text-ink">Срезка старого стекла</h4>
            <p class="mt-1 text-xs text-muted">Срезка полимерной струной без повреждения ЛКП проёма и проводки обогрева.</p>
        </div>

        {{-- Шаг 3 --}}
        <div class="relative rounded-2xl border border-line bg-sunken p-4">
            <div class="flex items-center justify-between">
                <span class="grid h-7 w-7 place-items-center rounded-full bg-brand font-display text-xs font-black text-on-brand">3</span>
                <span class="text-[10px] font-semibold text-faint">25 мин</span>
            </div>
            <h4 class="mt-3 text-sm font-bold text-ink">Праймер и вклейка</h4>
            <p class="mt-1 text-xs text-muted">Обезжиривание, активатор адгезии и нанесение герметика Sika / Betaseal.</p>
        </div>

        {{-- Шаг 4 --}}
        <div class="relative rounded-2xl border border-line bg-sunken p-4">
            <div class="flex items-center justify-between">
                <span class="grid h-7 w-7 place-items-center rounded-full bg-brand font-display text-xs font-black text-on-brand">4</span>
                <span class="text-[10px] font-semibold text-faint">20 мин</span>
            </div>
            <h4 class="mt-3 text-sm font-bold text-ink">Установка молдингов</h4>
            <p class="mt-1 text-xs text-muted">Уплотнители, накладки и датчики ставятся на место, стыки проверяются на подтёки.</p>
        </div>

        {{-- Шаг 5 --}}
        <div class="relative rounded-2xl border border-ok/30 bg-ok-soft p-4">
            <div class="flex items-center justify-between">
                <span class="grid h-7 w-7 place-items-center rounded-full bg-ok font-display text-xs font-black text-on-brand">5</span>
                <span class="text-[10px] font-bold text-ok-ink">1 Год</span>
            </div>
            <h4 class="mt-3 text-sm font-bold text-ink">Выдача и гарантия</h4>
            <p class="mt-1 text-xs text-muted">Печать гарантийного талона, памятка водителю на первые 48 часов.</p>
        </div>
    </div>

    {{-- Памятка на первые 48 часов после замены --}}
    <div class="mt-5 rounded-2xl border border-warn/30 bg-warn-soft/50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <strong class="text-xs font-extrabold uppercase tracking-wider text-warn-ink">
                Правила первых 48 часов после вклейки (для сохранения гарантии)
            </strong>
            <span class="text-[11px] font-semibold text-muted">Полимеризация герметика — 24–48 ч</span>
        </div>
        <div class="mt-3 grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl bg-surface/90 p-3">
                <strong class="font-bold text-ink">Без мойки Kärcher</strong>
                <p class="mt-0.5 text-muted">Не мыть авто под высоким давлением, чтобы струя не продавила свежий клеевой шов.</p>
            </div>
            <div class="rounded-xl bg-surface/90 p-3">
                <strong class="font-bold text-ink">Ровная парковка</strong>
                <p class="mt-0.5 text-muted">Не ставить машину одним колесом на бордюр и не поднимать домкратом / подъёмником.</p>
            </div>
            <div class="rounded-xl bg-surface/90 p-3">
                <strong class="font-bold text-ink">Скотч — на 24 часа</strong>
                <p class="mt-0.5 text-muted">Не снимать верхние фиксирующие ленты первые сутки — они держат уровень посадки.</p>
            </div>
            <div class="rounded-xl bg-surface/90 p-3">
                <strong class="font-bold text-ink">Плавный ход до 80 км/ч</strong>
                <p class="mt-0.5 text-muted">Избегать глубоких ям, резких торможений и сильного хлопанья дверями при закрытых окнах.</p>
            </div>
        </div>
    </div>
</div>
