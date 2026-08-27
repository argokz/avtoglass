@extends('layouts.app')

@section('title', "Стекло {$brand->name} {$model->name} — цены и подбор")
@section('description', "Лобовые, задние и боковые стёкла {$brand->name} {$model->name}. Сравнение предложений поставщиков.")

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.brands') }}" class="transition hover:text-ink">Каталог</a>
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.models', $brand) }}" class="transition hover:text-ink">{{ $brand->name }}</a>
    <span class="text-line-strong">/</span><span class="text-ink">{{ $model->name }}</span>
@endsection

@section('content')

<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">
            Стекло {{ $brand->name }} {{ $model->name }}@if ($filter->year), {{ $filter->year }}@endif
        </h1>
        <p class="mt-1.5 text-sm text-muted">
            Найдено позиций: {{ $products->total() }}
            {{-- Поколение — ответ на выбранный год, а не ещё один фильтр:
                 покупатель называет год, а «XV40» видит как подтверждение --}}
            @if ($generation && $generation->title())
                · поколение <span class="font-semibold text-ink">{{ $generation->title() }}</span>,
                <span class="text-faint">{{ $generation->yearsLabel() }}</span>
            @endif
        </p>
    </div>

    @if ($products->total() > 1)
        <form method="get" class="flex items-center gap-2">
            {{-- Остальные фильтры переносим в скрытые поля, иначе смена
                 сортировки сбросит и тип стекла, и год --}}
            @foreach ($filter->toQuery(['sort' => null]) as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <label for="sort" class="text-sm whitespace-nowrap text-muted">Сортировка</label>
            <select id="sort" name="sort" onchange="this.form.submit()"
                    class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                           focus:border-brand focus:outline-none">
                @foreach (App\Catalog\ProductSort::cases() as $option)
                    <option value="{{ $option->value }}" @selected($filter->sort === $option)>
                        {{ $option->label() }}
                    </option>
                @endforeach
            </select>
        </form>
    @endif
</div>

{{--
    Год выпуска.

    Camry 2006 и Camry 2018 — разные стёкла, и покупатель их не различает.
    Год — единственное, что он про свою машину знает точно, поэтому этот
    фильтр стоит выше типов стекла: сначала «какая машина», потом «какое стекло».
--}}
@if ($years !== [])
    <form method="get" class="mt-5 flex flex-wrap items-center gap-2 rounded-card border border-line bg-surface px-4 py-3">
        @foreach ($filter->toQuery(['year' => null]) as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <label for="year" class="text-sm font-semibold whitespace-nowrap">Год выпуска</label>

        <select id="year" name="year" onchange="this.form.submit()"
                class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                       focus:border-brand focus:outline-none">
            <option value="">Любой</option>
            @foreach ($years as $year)
                <option value="{{ $year }}" @selected($filter->year === $year)>{{ $year }}</option>
            @endforeach
        </select>

        @if ($filter->year)
            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['year' => null]))) }}"
               class="text-sm text-muted underline-offset-4 transition hover:text-ink hover:underline">
                сбросить
            </a>
        @else
            <span class="text-sm text-muted">Camry 2006 и Camry 2018 — разные стёкла</span>
        @endif
    </form>
@endif

@if ($types->isNotEmpty())
    <div class="scroll-row mt-5 sm:flex-wrap" role="group" aria-label="Фильтр по типу стекла">
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => null]))) }}"
           class="rounded-pill border px-4 py-2 text-sm font-semibold transition
                  {{ request('type')
                      ? 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink'
                      : 'border-brand bg-brand text-on-brand' }}">
            Все
        </a>

        {{-- Отдельно от типов стекла: это не «что», а «когда» --}}
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery([
               'in_stock' => $filter->inStockOnly ? null : 1,
           ]))) }}"
           class="flex items-center gap-2 rounded-pill border px-4 py-2 text-sm font-semibold transition
                  {{ $filter->inStockOnly
                      ? 'border-brand bg-brand text-on-brand'
                      : 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink' }}">
            <span class="h-2 w-2 rounded-full {{ $filter->inStockOnly ? 'bg-on-brand' : 'bg-brand' }}"></span>
            В наличии
        </a>

        @foreach ($types as $type => $count)
            @php $enum = App\Enums\GlassType::tryFrom($type); @endphp
            @continue(! $enum)

            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => $type]))) }}"
               class="flex items-center gap-2 rounded-pill border px-4 py-2 text-sm font-semibold transition
                      {{ request('type') === $type
                          ? 'border-brand bg-brand text-on-brand'
                          : 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink' }}">
                <x-glass-icon :type="$type" class="h-4 w-5 shrink-0 opacity-70" />
                {{ $enum->label() }}
                <span class="opacity-55">{{ $count }}</span>
            </a>
        @endforeach
    </div>
@endif

@if ($products->isEmpty())
    <div class="mt-8 rounded-card border border-line bg-surface px-6 py-14 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-line-strong">
            <x-glass-icon type="windshield" class="h-7 w-7" />
        </span>
        <p class="mt-4 font-display font-bold">Под этот фильтр ничего не нашлось</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            @if ($filter->year)
                {{-- Называем самую вероятную причину: год отсекает сильнее
                     остальных фильтров, и снимать надо в первую очередь его --}}
                Возможно, дело в годе выпуска — на {{ $filter->year }} год позиций нет.
                Снимите его или позвоните: подберём вручную по VIN.
            @else
                Снимите фильтр по типу стекла или позвоните — подберём вручную по VIN.
            @endif
        </p>
        <a href="{{ route('catalog.products', [$brand, $model]) }}"
           class="mt-5 inline-block rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand
                  transition hover:bg-brand-hover">
            Показать все стёкла
        </a>
    </div>
@else
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
