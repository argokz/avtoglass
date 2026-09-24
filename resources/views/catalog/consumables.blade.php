@extends('layouts.app')

@section('title', 'Молдинги, клей и расходники — '.settings()->brandName())
@section('description', 'Молдинги, подложки, полимеры и клей для установки автостекла. Цены и наличие уточняет менеджер.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Расходники</span>
@endsection

@section('content')

<div class="py-2">
    <h1 class="text-2xl font-extrabold sm:text-3xl">Молдинги, клей и расходники</h1>
    <p class="mt-2 max-w-2xl text-sm text-muted">
        Универсальные позиции — подбираются не по машине, а по размеру и типу профиля.
        Если не уверены, что подойдёт, назовите машину менеджеру: подскажет по стеклу.
    </p>

    <form method="get" class="mt-5 max-w-lg" role="search">
        <div class="relative flex gap-2">
            <div class="relative flex-1">
                <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-faint"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
                </svg>
                <label class="sr-only" for="consumable-search">Поиск по расходникам</label>
                <input id="consumable-search" type="search" name="q" value="{{ $q }}"
                       placeholder="Молдинг, подложка, полимер"
                       class="input !rounded-pill !py-2.5 !pr-4 !pl-10 !text-sm">
            </div>
            <button type="submit" class="btn btn-primary btn-sm shrink-0">Найти</button>
        </div>
    </form>

    @if ($products->isEmpty())
        <div class="card mt-6 px-5 py-12 text-center">
            <p class="font-extrabold">
                @if ($q)
                    По запросу «{{ $q }}» ничего не нашли
                @else
                    Расходников пока нет
                @endif
            </p>
            <p class="mt-1.5 text-sm text-muted">
                Позвоните — подберём по образцу:
                <a href="tel:{{ settings()->phones()[0]['tel'] ?? '' }}" class="font-bold text-brand-ink">{{ settings()->phones()[0]['display'] ?? '' }}</a>
            </p>
        </div>
    @else
        <p class="mt-5 text-sm text-muted">
            {{ $products->total() }} {{ склонение($products->total(), 'позиция', 'позиции', 'позиций') }}
        </p>

        <ul class="card mt-3 divide-y divide-line overflow-hidden !p-0">
            @foreach ($products as $product)
                <li>
                    <a href="{{ route('catalog.product', $product) }}"
                       class="flex items-baseline justify-between gap-4 px-4 py-4 transition hover:bg-sunken sm:px-5">
                        <span class="min-w-0">
                            <span class="block truncate font-bold">{{ $product->name }}</span>
                            @if (($supplier = $product->offers->first()?->supplier) && $supplier->slug !== 'ne-ukazan')
                                <span class="mt-0.5 block text-xs text-faint">{{ $supplier->name }}</span>
                            @endif
                        </span>
                        <span class="price price-brand shrink-0 text-lg">
                            {{ number_format((float) $product->price_min, 0, ',', ' ') }} ₸
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-6">
            {{ $products->links() }}
        </div>
    @endif

    <p class="mt-6 text-xs text-muted">
        Цена за единицу по прайсу. Молдинг продаётся метражом — сколько нужно на вашу машину, скажет мастер.
    </p>
</div>

@endsection
