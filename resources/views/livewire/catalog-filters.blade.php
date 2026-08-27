{{--
    Подбор стекла на странице модели.

    Фильтры остались ссылками с настоящим href: поисковик обходит их как
    обычные страницы, а у человека wire:click.prevent перехватывает клик
    и обновляет только выдачу. Отсюда пара href + wire:click у каждого
    фильтра — это не дублирование, а две аудитории одного элемента.
--}}
<div>

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
        <div class="flex items-center gap-2">
            <label for="sort" class="text-sm whitespace-nowrap text-muted">Сортировка</label>
            <select id="sort" wire:model.live="sort"
                    class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                           focus:border-brand focus:outline-none">
                @foreach (App\Catalog\ProductSort::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

{{--
    Год выпуска.

    Camry 2006 и Camry 2018 — разные стёкла, и покупатель их не различает.
    Год — единственное, что он про свою машину знает точно, поэтому этот
    фильтр стоит выше типов стекла: сначала «какая машина», потом «какое стекло».
--}}
@if ($years !== [])
    <div class="mt-5 flex flex-wrap items-center gap-2 rounded-card border border-line bg-surface px-4 py-3">
        <label for="year" class="text-sm font-semibold whitespace-nowrap">Год выпуска</label>

        <select id="year" wire:model.live="year"
                class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                       focus:border-brand focus:outline-none">
            <option value="">Любой</option>
            @foreach ($years as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>

        @if ($filter->year)
            <button type="button" wire:click="$set('year', '')"
                    class="text-sm text-muted underline-offset-4 transition hover:text-ink hover:underline">
                сбросить
            </button>
        @else
            <span class="text-sm text-muted">Camry 2006 и Camry 2018 — разные стёкла</span>
        @endif

        <a href="{{ route('vin.form') }}" wire:navigate
           class="ml-auto text-sm font-semibold text-brand-ink underline-offset-4 hover:underline">
            Не знаете год? Определим по VIN
        </a>
    </div>
@endif

@if ($types->isNotEmpty())
    <div class="scroll-row mt-5 sm:flex-wrap" role="group" aria-label="Фильтр по типу стекла">
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => null]))) }}"
           wire:click.prevent="$set('type', '')"
           class="rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                  {{ $filter->type
                      ? 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink'
                      : 'border-brand bg-brand text-on-brand' }}">
            Все
        </a>

        {{-- Отдельно от типов стекла: это не «что», а «когда» --}}
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery([
               'in_stock' => $filter->inStockOnly ? null : 1,
           ]))) }}"
           wire:click.prevent="toggleStock"
           class="flex items-center gap-2 rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                  {{ $filter->inStockOnly
                      ? 'border-brand bg-brand text-on-brand'
                      : 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink' }}">
            <span class="h-2 w-2 rounded-full {{ $filter->inStockOnly ? 'bg-on-brand' : 'bg-brand' }}"></span>
            В наличии
        </a>

        @foreach ($types as $type => $count)
            @php($enum = App\Enums\GlassType::tryFrom($type))
            @continue(! $enum)

            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => $type]))) }}"
               wire:click.prevent="selectType('{{ $type }}')"
               class="flex items-center gap-2 rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                      {{ $filter->type?->value === $type
                          ? 'border-brand bg-brand text-on-brand'
                          : 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink' }}">
                <x-glass-icon :type="$type" class="h-4 w-5 shrink-0 opacity-70" />
                {{ $enum->label() }}
                <span class="opacity-55">{{ $count }}</span>
            </a>
        @endforeach
    </div>
@endif

{{--
    Марка самого стекла.

    Второй по важности вопрос после цены: на одну машину китайское стекло
    и европейское различаются втрое, и покупатель выбирает именно здесь.
    Ниже типов стекла намеренно — сначала «что мне нужно», потом «чьё».
--}}
@if ($suppliers->count() > 1)
    <div class="scroll-row mt-3 sm:flex-wrap" role="group" aria-label="Фильтр по марке стекла">
        <span class="self-center pr-1 text-sm whitespace-nowrap text-muted">Марка стекла</span>

        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['brand' => null]))) }}"
           wire:click.prevent="$set('supplierId', 0)"
           class="rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                  {{ $filter->supplierId
                      ? 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink'
                      : 'border-brand bg-brand text-on-brand' }}">
            Любая
        </a>

        @foreach ($suppliers as $supplier)
            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['brand' => $supplier->id]))) }}"
               wire:click.prevent="$set('supplierId', {{ $supplier->id }})"
               class="flex items-center gap-2 rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                      {{ $filter->supplierId === $supplier->id
                          ? 'border-brand bg-brand text-on-brand'
                          : 'border-line bg-surface text-muted hover:border-brand/40 hover:text-ink' }}">
                {{ $supplier->name }}
                <span class="opacity-55">{{ $supplier->total }}</span>
            </a>
        @endforeach
    </div>
@endif

{{--
    Пока идёт запрос, выдача приглушается.

    Без этого при смене года экран выглядит замершим: цифры те же, а новые
    ещё не пришли, и человек нажимает второй раз.
--}}
<div wire:loading.class="opacity-40" wire:target="year,sort,type,inStock,supplierId,selectType,toggleStock,resetFilters,gotoPage,nextPage,previousPage"
     class="transition-opacity duration-150">

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
        <button type="button" wire:click="resetFilters"
                class="mt-5 inline-block rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand
                       transition hover:bg-brand-hover">
            Показать все стёкла
        </button>
    </div>
@else
    <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        @foreach ($products as $product)
            <x-product-card :product="$product" wire:key="product-{{ $product->id }}" />
        @endforeach
    </div>

    @if ($products->hasPages())
        <div class="mt-8">{{ $products->links('pagination') }}</div>
    @endif
@endif

</div>
</div>
