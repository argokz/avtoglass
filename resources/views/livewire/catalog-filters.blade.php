{{--
    Подбор стекла на странице модели.

    Фильтры остались ссылками с настоящим href: поисковик обходит их как
    обычные страницы, а у человека wire:click.prevent перехватывает клик
    и обновляет только выдачу. Отсюда пара href + wire:click у каждого
    фильтра — это не дублирование, а две аудитории одного элемента.
--}}
<div>

<div class="flex flex-wrap items-end justify-between gap-4">
    <div class="flex min-w-0 items-center gap-3 sm:gap-4">
        <x-car-model-image :model="$model" :brand="$brand" class="h-14 w-24 shrink-0 sm:h-16 sm:w-28" />
        <div class="min-w-0">
            <h1 class="text-2xl font-extrabold sm:text-3xl">
                Стекло {{ $brand->name }} {{ $model->name }}@if ($filter->year), {{ $filter->year }}@endif
            </h1>
            <p class="mt-1.5 text-sm text-muted">
                Найдено позиций: {{ $products->total() }}
                @if ($generation && $generation->title())
                    · поколение <span class="font-semibold text-ink">{{ $generation->title() }}</span>,
                    <span class="text-muted">{{ $generation->yearsLabel() }}</span>
                @endif
            </p>
        </div>
    </div>

    @if ($products->total() > 1)
        <div class="flex items-center gap-2">
            <label for="sort" class="text-sm whitespace-nowrap text-muted">Сортировка</label>
            <select id="sort" wire:model.live="sort" class="select !w-auto !rounded-pill !py-2 !text-sm !font-semibold">
                @foreach (App\Catalog\ProductSort::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>

@if (текущийПокупатель()?->seesWholesalePrices())
    <p class="mt-4 rounded-2xl bg-brand-soft px-4 py-3 text-sm text-brand-ink">
        Оптовые цены. Еврокод на карточке, в корзину — сразу со списка.
    </p>
@endif

@if ($years !== [] && settings()->enabled('year_filter'))
    <div class="card mt-5 flex flex-wrap items-center gap-2 px-4 py-3.5">
        <label for="year" class="text-sm font-bold whitespace-nowrap">Какой год у машины?</label>

        <select id="year" wire:model.live="year" class="select !w-auto !rounded-pill !py-2 !text-sm !font-semibold">
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
            <span class="text-sm text-muted">
                Разные годы {{ $brand->name }} {{ $model->name }} — разные стёкла
            </span>
        @endif

        @if (settings()->enabled('vin_lookup'))
        <a href="{{ route('vin.form') }}" wire:navigate
           class="ml-auto text-sm font-bold text-brand-ink underline-offset-4 hover:underline">
            Не знаете год? Определим по VIN
        </a>
        @endif
    </div>
@endif

@if ($types->isNotEmpty())
    <div class="scroll-row mt-5 sm:flex-wrap" role="group" aria-label="Фильтр по типу стекла">
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => null]))) }}"
           wire:click.prevent="$set('type', '')"
           class="chip {{ ! $filter->type ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
            Все
        </a>

        @if (settings()->enabled('display_stock'))
        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery([
               'in_stock' => $filter->inStockOnly ? null : 1,
           ]))) }}"
           wire:click.prevent="toggleStock"
           class="chip {{ $filter->inStockOnly ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
            <span class="h-2 w-2 rounded-full {{ $filter->inStockOnly ? 'bg-on-brand' : 'bg-brand' }}"></span>
            В наличии
        </a>
        @endif

        @foreach ($types as $type => $count)
            @php($enum = App\Enums\GlassType::tryFrom($type))
            @continue(! $enum)

            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['type' => $type]))) }}"
               wire:click.prevent="selectType('{{ $type }}')"
               class="chip {{ $filter->type?->value === $type ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
                <x-glass-icon :type="$type" class="h-4 w-5 shrink-0 opacity-70" />
                {{ $enum->label() }}
                <span class="opacity-55">{{ $count }}</span>
            </a>
        @endforeach
    </div>
@endif

@if ($suppliers->count() > 1)
    <div class="scroll-row mt-3 sm:flex-wrap" role="group" aria-label="Фильтр по марке стекла">
        <span class="self-center pr-1 text-sm whitespace-nowrap text-muted">Марка стекла</span>

        <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['brand' => null]))) }}"
           wire:click.prevent="$set('supplierId', 0)"
           class="chip {{ ! $filter->supplierId ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
            Любая
        </a>

        @foreach ($suppliers as $supplier)
            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery(['brand' => $supplier->id]))) }}"
               wire:click.prevent="$set('supplierId', {{ $supplier->id }})"
               class="chip {{ $filter->supplierId === $supplier->id ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
                {{ $supplier->name }}
                <span class="opacity-55">{{ $supplier->total }}</span>
            </a>
        @endforeach
    </div>
@endif

@if ($optionCounts->contains(fn (int $count) => $count > 0))
    <div class="scroll-row mt-3 sm:flex-wrap" role="group" aria-label="Фильтр по комплектации">
        <span class="self-center pr-1 text-sm whitespace-nowrap text-muted">Комплектация</span>

        @foreach (App\Models\Product::STOREFRONT_OPTIONS as $flag => $label)
            @continue($optionCounts[$flag] < 1)

            <a href="{{ route('catalog.products', array_merge([$brand, $model], $filter->toQuery([
                   $flag => in_array($flag, $filter->options, true) ? null : 1,
               ]))) }}"
               wire:click.prevent="toggleOption('{{ $flag }}')"
               class="chip {{ in_array($flag, $filter->options, true) ? 'chip-active !border-brand !bg-brand !text-on-brand' : '' }}">
                {{ $label }}
                <span class="opacity-55">{{ $optionCounts[$flag] }}</span>
            </a>
        @endforeach
    </div>
@endif

{{-- Skeleton при загрузке --}}
<div wire:loading.flex wire:target="year,sort,type,inStock,supplierId,selectType,toggleStock,toggleOption,resetFilters,camera,rain_sensor,sun_band,heated_wipers,heated_full,gotoPage,nextPage,previousPage"
     class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    @for ($i = 0; $i < 8; $i++)
        <div class="card overflow-hidden !p-0">
            <div class="skeleton aspect-4/3 !rounded-none"></div>
            <div class="space-y-2 p-4">
                <div class="skeleton h-4 w-4/5"></div>
                <div class="skeleton h-3 w-1/2"></div>
                <div class="skeleton mt-3 h-6 w-1/3"></div>
            </div>
        </div>
    @endfor
</div>

<div wire:loading.remove wire:target="year,sort,type,inStock,supplierId,selectType,toggleStock,toggleOption,resetFilters,camera,rain_sensor,sun_band,heated_wipers,heated_full,gotoPage,nextPage,previousPage">

@if ($products->isEmpty())
    <div class="card mt-8 px-6 py-14 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-line-strong">
            <x-glass-icon type="windshield" class="h-7 w-7" />
        </span>
        <p class="mt-4 font-display text-lg font-extrabold">Под этот фильтр ничего не нашлось</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            @if ($filter->year)
                Возможно, дело в годе выпуска — на {{ $filter->year }} год позиций нет.
                Снимите его или позвоните: подберём вручную по VIN.
            @else
                Снимите фильтр по типу стекла или позвоните — подберём вручную по VIN.
            @endif
        </p>
        <button type="button" wire:click="resetFilters" class="btn btn-primary mt-5">
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
