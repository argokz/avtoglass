@extends('layouts.app')

@section('title', "Автостекло {$brand->name} — все модели")
@section('description', "Стёкла для {$brand->name}: лобовые, задние и боковые. Подбор по модели, цены нескольких поставщиков.")

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.brands') }}" class="transition hover:text-ink">Каталог</a>
    <span class="text-line-strong">/</span><span class="text-ink">{{ $brand->name }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-center gap-3">
    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-ink">
        <x-glass-icon type="windshield" class="h-6 w-6" />
    </span>
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Стекло {{ $brand->name }}</h1>
        <p class="mt-0.5 text-sm text-muted">{{ $models->count() }} моделей с подходящим стеклом</p>
    </div>
</div>

{{-- Поиск по списку: у Toyota три сотни моделей, глазами искать невозможно. --}}
<div class="relative mt-6 max-w-md">
    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-faint"
         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
    </svg>
    <input type="search" data-filter-input data-filter-target="#model-list" placeholder="Фильтр по названию модели"
           class="w-full rounded-pill border border-line bg-surface py-2.5 pr-4 pl-10 text-sm
                  placeholder:text-faint focus:border-brand focus:outline-none">
</div>

<ul id="model-list" class="mt-5 grid gap-x-6 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ($models as $model)
        <li data-filter-item="{{ mb_strtolower($model->name) }}">
            <a href="{{ route('catalog.products', [$brand, $model]) }}"
               class="flex items-baseline justify-between gap-2 border-b border-line px-2 py-2.5 text-sm
                      transition hover:bg-sunken hover:text-brand">
                <span class="font-medium">{{ $model->name }}</span>
                <span class="shrink-0 rounded-pill bg-sunken px-2 py-0.5 text-xs text-faint">
                    {{ $model->products_count }}
                </span>
            </a>
        </li>
    @endforeach
</ul>

<p data-filter-empty hidden class="mt-6 rounded-card border border-line bg-surface p-6 text-center text-sm text-muted">
    Такой модели в списке нет. Попробуйте другое написание или
    <a href="{{ route('search') }}" class="font-semibold text-brand hover:underline">поищите по еврокоду</a>.
</p>

@endsection
