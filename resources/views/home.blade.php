@extends('layouts.app')

@section('title', 'Автостекло с установкой — подбор по марке и модели')

@section('content')

<section class="relative overflow-hidden rounded-card bg-night text-on-night">
    {{-- мягкое свечение вместо плоской заливки: даёт глубину без картинки --}}
    <div class="pointer-events-none absolute -top-28 -right-24 h-80 w-80 rounded-full bg-brand/25 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-accent/15 blur-3xl"></div>

    <div class="relative px-5 py-10 sm:px-10 sm:py-14 lg:py-16">
        <span class="inline-flex items-center gap-2 rounded-pill bg-white/10 px-3 py-1.5 text-xs font-semibold">
            <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
            {{ number_format($stats['products'], 0, ',', ' ') }} позиций в наличии и под заказ
        </span>

        <h1 class="mt-5 max-w-2xl font-display text-3xl leading-[1.1] font-extrabold sm:text-4xl lg:text-5xl">
            Подберём стекло<br class="hidden sm:block"> точно под вашу машину
        </h1>

        <p class="mt-4 max-w-xl text-sm text-on-night-muted sm:text-base">
            Лобовые, задние и боковые стёкла от {{ $stats['brands'] }} марок.
            Сравниваем предложения поставщиков, ставим и калибруем камеры ADAS.
        </p>

        <form action="{{ route('pick') }}" method="post" data-car-picker
              class="mt-8 grid gap-2.5 rounded-card border border-white/10 bg-white/8 p-2.5 backdrop-blur
                     sm:grid-cols-[1fr_1fr_auto] sm:gap-3 sm:p-3">
            @csrf

            <label class="sr-only" for="pick-brand">Марка автомобиля</label>
            <select id="pick-brand" name="brand" required data-picker-brand
                    class="w-full rounded-xl border-0 bg-surface px-4 py-3.5 text-sm font-medium text-ink
                           focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">Марка автомобиля</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}" data-slug="{{ $brand->slug }}">{{ $brand->name }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="pick-model">Модель</label>
            <select id="pick-model" name="model" data-picker-model disabled
                    class="w-full rounded-xl border-0 bg-surface px-4 py-3.5 text-sm font-medium text-ink
                           disabled:bg-surface/50 disabled:text-faint focus:outline-none focus:ring-2 focus:ring-accent">
                <option value="">Сначала выберите марку</option>
            </select>

            <button class="rounded-xl bg-brand px-7 py-3.5 text-sm font-bold whitespace-nowrap text-on-brand
                           transition hover:bg-brand-hover active:scale-[0.98]">
                Подобрать
            </button>
        </form>

        <p class="mt-3.5 text-xs text-on-night-muted sm:text-sm">
            Не знаете модель? Введите еврокод с наклейки на стекле — например,
            <span class="font-mono font-semibold text-on-night">7260AGNGYV</span>.
        </p>
    </div>
</section>

<section class="mt-8 sm:mt-12">
    <div class="mb-4 flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-bold sm:text-2xl">Популярные марки</h2>
        <a href="{{ route('catalog.brands') }}" class="text-sm font-semibold text-brand hover:underline">
            Все марки →
        </a>
    </div>

    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 lg:grid-cols-6">
        @foreach ($popular as $brand)
            <a href="{{ route('catalog.models', $brand) }}"
               class="group rounded-card border border-line bg-surface p-4 transition
                      hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-card">
                <div class="font-display font-bold transition group-hover:text-brand">{{ $brand->name }}</div>
                <div class="mt-0.5 text-xs text-faint">{{ $brand->models_count }} моделей</div>
            </a>
        @endforeach
    </div>
</section>

<section class="mt-8 sm:mt-12">
    <h2 class="text-xl font-bold sm:text-2xl">Как это работает</h2>

    <ol class="mt-4 grid gap-3 sm:grid-cols-3 sm:gap-4">
        @foreach ([
            ['Находим стекло', 'По марке и модели или по еврокоду с наклейки. Показываем только то, что встанет на вашу машину.'],
            ['Сравниваем цены', 'На одну позицию собираем предложения разных производителей — от эконома до оригинала.'],
            ['Ставим и калибруем', 'Меняем стекло и калибруем камеру ADAS: без калибровки системы помощи водителю работают неверно.'],
        ] as $i => [$title, $text])
            <li class="relative rounded-card border border-line bg-surface p-5">
                <span class="font-display text-3xl leading-none font-extrabold text-brand/25">0{{ $i + 1 }}</span>
                <h3 class="mt-2 font-bold">{{ $title }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-muted">{{ $text }}</p>
            </li>
        @endforeach
    </ol>
</section>

<section class="mt-8 grid gap-3 sm:mt-12 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['windshield', 'Лобовые', 'С полосой, датчиком дождя, обогревом и камерой'],
        ['rear', 'Задние', 'С обогревом и антенной'],
        ['side', 'Боковые', 'Опускные и глухие, левые и правые'],
        ['consumable', 'Молдинги и клей', 'Всё для установки'],
    ] as [$type, $title, $text])
        <div class="flex items-start gap-3.5 rounded-card border border-line bg-surface p-4">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-ink">
                <x-glass-icon :type="$type" class="h-6 w-6" />
            </span>
            <div>
                <h3 class="font-bold">{{ $title }}</h3>
                <p class="mt-0.5 text-xs leading-relaxed text-muted">{{ $text }}</p>
            </div>
        </div>
    @endforeach
</section>

@endsection
