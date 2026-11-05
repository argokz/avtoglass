@extends('layouts.app')

@section('title', settings()->brandName().' — автостекло с установкой')

@section('content')

<section class="store-hero" aria-labelledby="home-title">
    <div class="hero-copy">
        <p class="hero-location"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.5"/></svg> {{ currentCity() }} — подбор и установка</p>
        <h1 id="home-title">Стекло на какую машину?</h1>
        <p class="hero-lead">Подберите стекло по модели и году, сравните цены и выберите установку или доставку.</p>
        <div class="hero-links">
            <a href="#car-finder" class="btn btn-primary btn-lg">Подобрать по автомобилю</a>
            <a href="{{ route('search') }}" class="btn btn-ghost">Найти по артикулу</a>
        </div>
        <nav class="mt-4 flex flex-wrap gap-2 lg:hidden" aria-label="Быстрый выбор типа стекла (мобильный)">
            @foreach (['windshield' => 'Лобовые', 'rear' => 'Задние', 'side' => 'Боковые'] as $type => $label)
                <a href="{{ route('catalog.brands', ['type' => $type]) }}" class="chip !min-h-10 !px-3.5 !py-1.5 text-xs">
                    <x-glass-icon :type="$type" class="h-4 w-4 text-brand-ink" />
                    {{ $label }}
                </a>
            @endforeach
        </nav>
        @if (request()->cookie('storefront_mode') === 'chat' && settings()->aiChatEnabled())
            <a href="{{ route('assistant') }}" class="mt-4 inline-flex min-h-11 items-center font-bold text-brand-ink">Продолжить в чате →</a>
        @endif
    </div>
    <div class="hero-glass">
        <div class="glass-wordmark" aria-hidden="true">Автостеклоцентр</div>
        <svg viewBox="0 0 440 280" fill="none" class="hero-windshield" aria-hidden="true">
            <path d="M92 48 Q220 18 348 48 L402 226 Q220 263 38 226 Z" fill="url(#hero-glass-fill)" stroke="currentColor" stroke-width="2"/>
            <path d="M104 63 Q220 39 336 63 L382 214 Q220 244 58 214 Z" stroke="currentColor" opacity=".3"/>
            <path d="M155 48 98 227M285 38 220 241" stroke="white" stroke-width="20" opacity=".07"/>
            <path d="M92 48 Q220 18 348 48" stroke="#ef3340" stroke-width="4"/>
            <defs><linearGradient id="hero-glass-fill" x1="80" y1="40" x2="360" y2="260" gradientUnits="userSpaceOnUse"><stop stop-color="#81979d" stop-opacity=".65"/><stop offset="1" stop-color="#15272e" stop-opacity=".25"/></linearGradient></defs>
        </svg>
        <div class="glass-caption"><p>Каждая деталь имеет значение.</p><span>Датчики, обогрев, камера — учтём комплектацию.</span></div>
        <nav class="hero-glass-types" aria-label="Быстрый выбор типа стекла">
            @foreach (['windshield' => 'Лобовые', 'rear' => 'Задние', 'side' => 'Боковые'] as $type => $label)
                <a href="{{ route('catalog.brands', ['type' => $type]) }}">{{ $label }} <x-glass-icon :type="$type" class="h-5 w-5" /></a>
            @endforeach
        </nav>
    </div>
</section>

<section id="car-finder" class="finder-panel scroll-mt-64 lg:scroll-mt-48" aria-labelledby="finder-title">
    <div class="section-heading">
        <div><h2 id="finder-title">Подбор по автомобилю</h2><p class="mt-1 text-sm text-muted">Выберите марку и модель. Год поможет уточнить поколение.</p></div>
        <span class="finder-note"><x-glass-icon type="windshield" class="h-5 w-5" /> Учитываем совместимость</span>
    </div>
    <form action="{{ route('pick') }}" method="post" data-car-picker
          data-models-base="{{ url('/api/brands') }}"
          data-selected-model="{{ old('model') }}"
          class="finder-form grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.2fr_1.2fr_7rem_auto]">
        @csrf

        <div>
            <label class="mb-1.5 block text-xs font-bold text-muted" for="pick-brand">Марка</label>
            <select id="pick-brand" name="brand" required data-picker-brand
                    class="select @error('brand') input-error @enderror">
                <option value="">Выберите марку</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand->id }}" data-slug="{{ $brand->slug }}"
                            @selected((string) old('brand') === (string) $brand->id)>{{ $brand->name }}</option>
                @endforeach
            </select>
            @error('brand')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-xs font-bold text-muted" for="pick-model">Модель</label>
            <select id="pick-model" name="model" data-picker-model disabled class="select">
                <option value="">Сначала марка</option>
            </select>
            @error('model')
                <p class="mt-1 text-xs text-warn-ink" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="mb-1.5 block text-xs font-bold text-muted" for="pick-year">Год</label>
            <input id="pick-year" type="number" name="year" inputmode="numeric"
                   min="1980" max="{{ now()->year + 1 }}" placeholder="2018"
                   value="{{ old('year') }}"
                   class="input @error('year') input-error @enderror" autocomplete="off">
            @error('year')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-end">
            <button type="submit" class="btn btn-primary btn-lg w-full sm:!rounded-xl">
                Показать стёкла
            </button>
        </div>
    </form>
    <div class="finder-help">
        @if (settings()->enabled('vin_lookup'))
            <a href="{{ route('vin.form') }}">Не знаете комплектацию? Подбор по VIN →</a>
        @endif
        @if (settings()->aiChatEnabled())
            <a href="{{ route('assistant') }}">Помощь с подбором в чате →</a>
        @endif
        <a href="{{ текущийПокупатель() ? route('wholesale.index') : route('account.login') }}">Сервисам и магазинам — оптовые цены →</a>
    </div>
</section>

@if (! empty($myCar))
<section class="mt-8" aria-labelledby="my-car-title">
    <div class="section-heading">
        <div>
            <p class="eyebrow">ДЛЯ ВАШЕГО АВТО</p>
            <h2 id="my-car-title">Стёкла для {{ $myCar['label'] }}</h2>
        </div>
        <a href="{{ $myCar['url'] }}" class="text-sm font-bold text-brand-ink underline-offset-4 hover:underline">
            Все подходящие →
        </a>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($myCar['products'] as $product)
            <x-product-card :product="$product" />
        @endforeach
    </div>
</section>
@endif

<ol class="purchase-steps mt-6 grid list-none gap-3 sm:grid-cols-3">
    <li class="card flex gap-3 px-4 py-4">
        <span class="step-num">1</span>
        <div>
            <p class="font-extrabold">Машина</p>
            <p class="mt-0.5 text-sm text-muted">Марка, модель и год. У одного названия машины стекло меняется с поколением.</p>
        </div>
    </li>
    <li class="card flex gap-3 px-4 py-4">
        <span class="step-num">2</span>
        <div>
            <p class="font-extrabold">Стекло</p>
            <p class="mt-0.5 text-sm text-muted">Лобовое, заднее или боковое. Цены нескольких заводов рядом.</p>
        </div>
    </li>
    <li class="card flex gap-3 px-4 py-4">
        <span class="step-num">3</span>
        <div>
            <p class="font-extrabold">Как получите</p>
            <p class="mt-0.5 text-sm text-muted">Поставим в сервисе, с выездом, привезём стекло или заберёте сами.</p>
        </div>
    </li>
</ol>


<section class="cv-auto mt-10 sm:mt-12">
    <div class="mb-4 flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-extrabold sm:text-2xl">Марки</h2>
        <a href="{{ route('catalog.brands') }}" class="text-sm font-bold text-brand-ink hover:underline">
            Все {{ $stats['brands'] }} →
        </a>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($popular as $brand)
            <a href="{{ route('catalog.models', $brand) }}"
               class="card card-hover group flex items-center gap-3 p-4">
                @if ($brand->logoUrl())
                    <img src="{{ $brand->logoUrl() }}" alt="" loading="lazy" decoding="async"
                         class="h-9 w-9 shrink-0 object-contain">
                @else
                    <x-brand-logo :brand="$brand" class="h-9 w-9 shrink-0 text-muted" />
                @endif
                <span class="min-w-0">
                    <span class="block truncate font-display text-sm font-extrabold transition group-hover:text-brand-ink">{{ $brand->name }}</span>
                    <span class="block text-xs text-faint">{{ $brand->models_count }} {{ склонение($brand->models_count, 'модель', 'модели', 'моделей') }}</span>
                </span>
            </a>
        @endforeach
    </div>
</section>

@if ($popularModels->isNotEmpty())
    <section class="cv-auto mt-10 sm:mt-12">
        <h2 class="mb-4 text-xl font-extrabold sm:text-2xl">Часто ищут</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($popularModels as $model)
                <a href="{{ route('catalog.products', [$model->brand, $model]) }}"
                   class="card card-hover group flex flex-col overflow-hidden p-3">
                    <x-car-model-image :model="$model" :brand="$model->brand" class="mb-2 h-20 sm:h-24" />
                    <span class="truncate text-xs text-faint">{{ $model->brand->name }}</span>
                    <span class="truncate font-display text-sm font-extrabold transition group-hover:text-brand-ink">
                        {{ $model->name }}
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif

<section class="cv-auto mt-10 sm:mt-12">
    <h2 class="mb-4 text-xl font-extrabold sm:text-2xl">Или по типу стекла</h2>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['windshield', 'Лобовые', 'windshield'],
            ['rear', 'Задние', 'rear'],
            ['side', 'Боковые', 'side'],
            ['consumable', 'Молдинги и клей', 'consumable'],
        ] as [$icon, $title, $type])
            <a href="{{ $type === 'consumable' ? route('catalog.consumables') : route('catalog.brands', ['type' => $type]) }}"
               class="card card-hover flex items-center gap-3 p-4">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-ink">
                    <x-glass-icon :type="$icon" class="h-5 w-5" />
                </span>
                <span class="font-extrabold">{{ $title }}</span>
            </a>
        @endforeach
    </div>
</section>

@if ($services->isNotEmpty())
    <section class="cv-auto mt-10 sm:mt-12">
        <h2 class="text-xl font-extrabold sm:text-2xl">Работы и цены</h2>
        <p class="mt-1 text-sm text-muted">Стекло считается отдельно. Итог со стеклом — в карточке, до звонка.</p>

        <div class="card mt-4 divide-y divide-line !py-0">
            @foreach ($services as $service)
                <div class="flex items-baseline justify-between gap-4 px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="font-bold">{{ $service->name }}</p>
                        @if ($service->duration_minutes)
                            <p class="mt-0.5 text-xs text-faint">около {{ $service->humanDuration() }}</p>
                        @endif
                    </div>
                    <p class="price price-brand shrink-0 text-lg">
                        {{ formatPrice((float) $service->price) }} ₸
                    </p>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-sm text-muted">Наличие и срок подтверждает менеджер.</p>
    </section>
@endif

<section class="cv-auto mt-10 grid gap-3 sm:mt-12 sm:grid-cols-3">
    <a href="https://2gis.kz/search/{{ rawurlencode(settings()->address()) }}"
       target="_blank" rel="noopener"
       class="card card-hover px-5 py-4 text-sm">
        <p class="text-xs font-bold text-faint">Адрес</p>
        <p class="mt-1.5 font-bold">{{ settings()->address() }}</p>
        <p class="mt-1 text-xs font-semibold text-brand-ink">Открыть в 2ГИС</p>
    </a>
    <div class="card px-5 py-4 text-sm">
        <p class="text-xs font-bold text-faint">Часы</p>
        <p class="mt-1.5 font-bold">{{ settings()->hours() }}</p>
        <p class="mt-1 text-xs text-muted">{{ implode(', ', settings()->cities()) }}</p>
    </div>
    <a href="tel:{{ settings()->phones()[0]['tel'] ?? '' }}"
       class="card card-hover px-5 py-4 text-sm">
        <p class="text-xs font-bold text-faint">Телефон</p>
        <p class="mt-1.5 font-bold">{{ settings()->phones()[0]['display'] ?? '' }}</p>
        <p class="mt-1 text-xs font-semibold text-brand-ink">Позвонить</p>
    </a>
</section>

@endsection
