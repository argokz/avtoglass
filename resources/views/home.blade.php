@extends('layouts.app')

@section('title', settings()->brandName().' — автостекло с установкой')

@section('content')

{{--
    Один вопрос на первый экран: какая машина.
    Остальное — после ответа. Год здесь же, потому что Camry 2006
    и Camry 2018 — разные стёкла, и спрашивать об этом на следующем
    шаге значит заставлять человека дважды объяснять одно и то же.
--}}
<section class="rounded-lg border border-line bg-surface px-5 py-8 shadow-card sm:px-10 sm:py-12">
    <p class="text-sm font-semibold text-muted">{{ settings()->brandName() }}</p>

    @if (request()->cookie('storefront_mode') === 'chat' && settings()->aiChatEnabled())
        <a href="{{ route('assistant') }}" class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-pill bg-brand-soft px-4 text-sm font-bold text-brand-ink">
            Продолжить в чате <span aria-hidden="true">→</span>
        </a>
    @endif

    <h1 class="mt-3 max-w-2xl font-display text-3xl leading-[1.1] font-extrabold tracking-tight sm:text-4xl">
        Стекло на какую машину?
    </h1>

    <p class="mt-3 max-w-xl text-sm text-muted sm:text-base">
        Марка, модель и год — покажем стёкла и цену с установкой.
    </p>

    <form action="{{ route('pick') }}" method="post" data-car-picker
          data-models-base="{{ url('/api/brands') }}"
          data-selected-model="{{ old('model') }}"
          class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1.2fr_1.2fr_7rem_auto]">
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

    <p class="mt-4 text-sm text-muted">
        @if (settings()->aiChatEnabled())
            Не хотите выбирать в форме —
            <a href="{{ route('assistant') }}" class="font-semibold text-brand-ink underline underline-offset-4">напишите помощнику</a>,
            он подберёт и оформит заявку в чате.
        @endif
        Код с уголка стекла — в поиск наверху.
        @if (settings()->enabled('vin_lookup'))
            Есть VIN —
            <a href="{{ route('vin.form') }}" class="font-semibold text-brand-ink underline underline-offset-4">подберём по нему</a>.
        @endif
        @php $покупатель = текущийПокупатель(); @endphp
        @if ($покупатель?->seesWholesalePrices())
            Оптовые цены открыты.
        @elseif ($покупатель?->wholesale_status === \App\Enums\WholesaleStatus::Pending)
            Заявку на опт смотрит менеджер — пока цены розничные.
        @else
            Сервисам и юрлицам —
            <a href="{{ $покупатель ? route('account.profile') : route('account.login') }}" class="font-semibold text-brand-ink underline underline-offset-4">
                {{ $покупатель ? 'заявка на опт в профиле' : 'войдите для оптовых цен' }}
            </a>.
        @endif
    </p>
</section>

<ol class="mt-6 grid list-none gap-3 sm:grid-cols-3">
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

@if ($services->isNotEmpty())
    <section class="mt-10 sm:mt-12">
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
                        {{ number_format((float) $service->price, 0, ',', ' ') }} ₸
                    </p>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-sm text-muted">Наличие и срок подтверждает менеджер.</p>
    </section>
@endif

<section class="mt-10 sm:mt-12">
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
    <section class="mt-10 sm:mt-12">
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

<section class="mt-10 sm:mt-12">
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

<section class="mt-10 grid gap-3 sm:mt-12 sm:grid-cols-3">
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
