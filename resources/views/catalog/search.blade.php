@extends('layouts.app')

@section('title', $q ? "Поиск: {$q}" : 'Поиск по каталогу автостекла')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Поиск</span>
@endsection

@section('content')

<p class="eyebrow mb-3">КАТАЛОГ АВТОСТЁКОЛ</p>
<h1 class="text-2xl font-bold sm:text-3xl">
    @if ($q)
        Результаты по запросу «{{ $q }}»
    @else
        Поиск по каталогу
    @endif
</h1>

@if (! $q)
    <div class="mt-6 rounded-card border border-line bg-surface p-6">
        <p class="text-sm text-muted">
            Введите марку, модель или еврокод со стекла. Еврокод — это буквенно-цифровая
            маркировка в углу стекла, по ней товар находится точнее всего.
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            @foreach (['7260AGNGYV', 'Camry', 'Hilux', 'Lada Vesta'] as $example)
                <a href="{{ route('search', ['q' => $example]) }}"
                   class="rounded-pill border border-line bg-sunken px-3.5 py-1.5 text-sm font-medium
                          transition hover:border-brand/40 hover:text-brand">
                    {{ $example }}
                </a>
            @endforeach
        </div>
    </div>
@elseif ($models->isEmpty() && $brands->isEmpty() && $products->isEmpty())
    <div class="mt-8 rounded-card border border-line bg-surface px-6 py-14 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-line-strong">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
            </svg>
        </span>
        <p class="mt-4 font-display font-bold">Ничего не нашлось</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            Проверьте раскладку или опишите машину — подберём стекло сами.
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
            @if (settings()->enabled('vin_lookup'))
                <a href="{{ route('vin.form') }}" class="btn btn-primary">Подбор по VIN</a>
            @endif
            <a href="{{ route('order.form') }}" class="btn btn-outline">Заявка на подбор</a>
            @if (settings()->enabled('whatsapp') && settings()->whatsapp())
                <a href="https://wa.me/{{ settings()->whatsapp() }}?text={{ rawurlencode('Здравствуйте! Не нашёл стекло по запросу «'.$q.'»') }}"
                   target="_blank" rel="noopener" class="btn btn-outline">
                    Написать в WhatsApp
                </a>
            @endif
            <a href="{{ route('catalog.brands') }}" class="btn btn-ghost">Открыть каталог</a>
        </div>
    </div>
@else
    @if ($models->isNotEmpty())
        <section class="mt-6">
            <h2 class="text-lg font-extrabold">Модели</h2>
            <p class="mt-1 text-sm text-muted">Сначала выберите машину — там отфильтруем стекло по году и типу.</p>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($models as $model)
                    <a href="{{ route('catalog.products', [$model->brand, $model]) }}"
                       class="card card-hover group flex flex-col overflow-hidden p-3 sm:p-4">
                        <x-car-model-image :model="$model" :brand="$model->brand" class="mb-3 h-24 sm:h-28" />
                        <span class="truncate text-xs font-semibold text-faint">{{ $model->brand->name }}</span>
                        <span class="truncate font-display text-sm font-extrabold transition group-hover:text-brand-ink">
                            {{ $model->name }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($brands->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-extrabold">Марки</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($brands as $brand)
                    <a href="{{ route('catalog.models', $brand) }}"
                       class="card card-hover group flex items-center gap-3 p-4">
                        @if ($brand->logoUrl())
                            <img src="{{ $brand->logoUrl() }}" alt="" loading="lazy" decoding="async"
                                 class="h-9 w-9 shrink-0 object-contain">
                        @else
                            <x-brand-logo :brand="$brand" class="h-9 w-9 shrink-0 text-ink" />
                        @endif
                        <span class="min-w-0">
                            <span class="block truncate font-display font-extrabold transition group-hover:text-brand-ink">
                                {{ $brand->name }}
                            </span>
                            <span class="mt-0.5 block text-xs text-faint">
                                {{ $brand->models_count }} {{ склонение($brand->models_count, 'модель', 'модели', 'моделей') }}
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($products->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-extrabold">Стёкла</h2>
            <p class="mt-1 text-sm text-muted">Найдено позиций: {{ $products->total() }}</p>

            <div class="catalog-grid mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>

            @if ($products->hasPages())
                <div class="mt-8">{{ $products->links("pagination") }}</div>
            @endif
        </section>
    @endif
@endif

@endsection
