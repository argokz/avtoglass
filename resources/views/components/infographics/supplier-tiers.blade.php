@props(['class' => ''])

<div class="rounded-card border border-line bg-surface p-6 shadow-sm {{ $class }}">
    <div class="flex items-center gap-3">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent-soft text-accent-ink">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3" />
            </svg>
        </span>
        <div>
            <h3 class="text-base font-bold text-ink">Чем отличаются производители автостекла</h3>
            <p class="text-xs text-muted">Все стёкла в каталоге сертифицированы (ECE R43 / ГОСТ) — разница в оптике, акустике и оснащении</p>
        </div>
    </div>

    <div class="mt-5 grid gap-4 md:grid-cols-3">
        {{-- 1. Конвейерный премиум (OEM) --}}
        <div class="flex flex-col justify-between rounded-2xl border border-line bg-sunken p-4">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <span class="badge badge-ok">Конвейер / OEM</span>
                    <span class="font-mono text-xs font-bold text-ok-ink">★★★★★</span>
                </div>
                <h4 class="mt-2.5 text-sm font-bold text-ink">AGC, Pilkington, Sekurit</h4>
                <p class="mt-1.5 text-xs leading-relaxed text-muted">
                    Поставщики заводов Toyota, Lexus, BMW, VAG. Эталонная геометрия без оптических искажений по краям, акустическая плёнка и 100% точность калибровки камер ADAS и проекции HUD.
                </p>
            </div>
            <div class="mt-3 border-t border-line pt-2.5 text-[11px] font-semibold text-muted">
                Кому подходит: свежие авто с камерами удержания полосы, HUD и датчиками
            </div>
        </div>

        {{-- 2. Заводской стандарт (Оптимальный выбор) --}}
        <div class="flex flex-col justify-between rounded-2xl border-2 border-brand/30 bg-brand-soft/25 p-4">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <span class="badge badge-brand">Выбор 70% клиентов</span>
                    <span class="font-mono text-xs font-bold text-brand-ink">★★★★☆</span>
                </div>
                <h4 class="mt-2.5 text-sm font-bold text-ink">Fuyao (FYG), Benson, Olimpia</h4>
                <p class="mt-1.5 text-xs leading-relaxed text-muted">
                    Крупнейшие мировые заводы (Fuyao поставляет стёкла на конвейеры VW, Geely, Chery, Ford). Крепкий триплекс, ровная шелкография, заводской пятак зеркала и молдинг в комплекте.
                </p>
            </div>
            <div class="mt-3 border-t border-line pt-2.5 text-[11px] font-semibold text-brand-ink">
                Кому подходит: ежедневная езда по городу и трассе без переплаты за логотип автоконцерна
            </div>
        </div>

        {{-- 3. Практичный бюджет --}}
        <div class="flex flex-col justify-between rounded-2xl border border-line bg-sunken p-4">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <span class="badge badge-muted">Эконом-класс</span>
                    <span class="font-mono text-xs font-bold text-muted">★★★☆☆</span>
                </div>
                <h4 class="mt-2.5 text-sm font-bold text-ink">XYG, KMK Glass, Стекло-Люкс</h4>
                <p class="mt-1.5 text-xs leading-relaxed text-muted">
                    Доступный многослойный триплекс по цене в 2–3 раза ниже оригинала. Проходит техосмотр, защищает от камней и ультрафиолета, выпускается в том числе на редкие и возрастные кузова.
                </p>
            </div>
            <div class="mt-3 border-t border-line pt-2.5 text-[11px] font-semibold text-muted">
                Кому подходит: рабочие машины, коммерческий транспорт, предпродажная подготовка
            </div>
        </div>
    </div>
</div>
