@extends('layouts.app')

@section('title', "Автостекло {$brand->name} — все модели")
@section('description', "Стёкла для {$brand->name}: лобовые, задние и боковые. Подбор по модели, цены нескольких поставщиков.")

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.brands', array_filter(['type' => $glassType?->value])) }}" class="transition hover:text-ink">Каталог</a>
    <span class="text-line-strong">/</span><span class="text-ink">{{ $brand->name }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-center gap-3">
    <span class="grid h-14 w-14 shrink-0 place-items-center overflow-hidden rounded-2xl bg-brand-soft p-2 text-brand-ink">
        @if ($brand->logoUrl())
            <img src="{{ $brand->logoUrl() }}" alt="" class="h-full w-full object-contain" loading="lazy" decoding="async">
        @else
            <x-brand-logo :brand="$brand" class="h-8 w-8" />
        @endif
    </span>
    <div>
        <h1 class="text-2xl font-extrabold sm:text-3xl">Какая модель {{ $brand->name }}?</h1>
        <p class="mt-0.5 text-sm text-muted">
            {{ $models->count() }} {{ склонение($models->count(), 'модель', 'модели', 'моделей') }} с подходящим стеклом
            @if ($glassType)
                · дальше покажем {{ mb_strtolower($glassType->label()) }}
            @endif
        </p>
    </div>
</div>

<div class="relative mt-6 max-w-md">
    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-faint"
         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
    </svg>
    <input type="search" data-filter-input data-filter-target="#model-list" placeholder="Фильтр по названию модели"
           class="input !rounded-pill !py-2.5 !pr-4 !pl-10 !text-sm">
</div>

<div id="model-list" class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
    @foreach ($models as $model)
        <div data-filter-item="{{ mb_strtolower($model->name) }}" class="flex">
            <a href="{{ route('catalog.products', array_filter(['brand' => $brand, 'model' => $model, 'type' => $glassType?->value])) }}"
               class="card card-hover group relative flex flex-1 flex-col overflow-hidden p-3 sm:p-4">

                <x-car-model-image :model="$model" :brand="$brand" class="mb-3 h-32 w-full" />

                <div class="mt-auto flex items-baseline justify-between gap-2 pt-1">
                    <span class="truncate font-display text-sm font-extrabold text-ink transition group-hover:text-brand-ink sm:text-base">
                        {{ $model->name }}
                    </span>
                    <span class="badge badge-brand shrink-0 !bg-brand-soft !text-brand-ink">
                        {{ $model->products_count }} стёкол
                    </span>
                </div>
            </a>
        </div>
    @endforeach
</div>

<p data-filter-empty hidden class="card mt-6 p-6 text-center text-sm text-muted">
    Такой модели в списке нет. Попробуйте другое написание или
    <a href="{{ route('search') }}" class="font-bold text-brand-ink hover:underline">поищите по еврокоду</a>.
</p>

@endsection
