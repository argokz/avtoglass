@extends('layouts.app')

@section('title', $q ? "Поиск: {$q}" : 'Поиск по каталогу автостекла')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Поиск</span>
@endsection

@section('content')

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
@elseif ($products->isEmpty())
    <div class="mt-8 rounded-card border border-line bg-surface px-6 py-14 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-line-strong">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
            </svg>
        </span>
        <p class="mt-4 font-display font-bold">Ничего не нашлось</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            Проверьте раскладку клавиатуры или зайдите через каталог марок.
        </p>
        <a href="{{ route('catalog.brands') }}"
           class="mt-5 inline-block rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand
                  transition hover:bg-brand-hover">
            Открыть каталог
        </a>
    </div>
@else
    <p class="mt-1.5 text-sm text-muted">Найдено позиций: {{ $products->total() }}</p>

    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($products as $product)
            <x-product-card :product="$product" />
        @endforeach
    </div>

    @if ($products->hasPages())
        <div class="mt-8">{{ $products->links("pagination") }}</div>
    @endif
@endif

@endsection
