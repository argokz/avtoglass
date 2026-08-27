@extends('layouts.app')

@section('title', $product->name)
@section('description', "{$product->name}. Цены нескольких поставщиков, гарантия, установка и калибровка.")

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.brands') }}" class="transition hover:text-ink">Каталог</a>
    @if ($first = $product->fitments->first())
        <span class="text-line-strong">/</span>
        <a href="{{ route('catalog.models', $first->carModel->brand) }}" class="transition hover:text-ink">
            {{ $first->carModel->brand->name }}
        </a>
        <span class="text-line-strong">/</span>
        <a href="{{ route('catalog.products', [$first->carModel->brand, $first->carModel]) }}"
           class="transition hover:text-ink">{{ $first->carModel->name }}</a>
    @endif
@endsection

@section('content')

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-10">

    <div class="min-w-0">
        <div class="relative grid aspect-video place-items-center overflow-hidden rounded-card
                    border border-line bg-surface text-line-strong">
            @if ($product->imageUrl())
                <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}"
                     class="h-full w-full object-contain">
            @else
                {{-- Фото у большинства позиций нет: прайсы приходят без картинок.
                     Значок типа стекла хотя бы отвечает на вопрос «лобовое или боковое». --}}
                <x-glass-icon :type="$product->glass_type" class="h-full w-full max-h-56 p-10" />
            @endif

            <span class="absolute top-4 left-4 rounded-pill bg-sunken px-3 py-1.5 text-xs font-semibold text-muted">
                {{ $product->glass_type?->label() }}
            </span>
        </div>

        <h1 class="mt-6 text-2xl leading-tight font-bold sm:text-3xl">{{ $product->name }}</h1>

        @if ($options = $product->activeOptions())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($options as $option)
                    <span class="rounded-pill bg-accent-soft px-3 py-1.5 text-xs font-semibold text-accent-ink">
                        {{ $option }}
                    </span>
                @endforeach
            </div>
        @endif

        @if ($product->camera)
            {{-- Про калибровку предупреждаем до заказа: это отдельная работа и отдельные
                 деньги, а без неё системы помощи водителю работают неверно. --}}
            <div class="mt-5 flex gap-3.5 rounded-card border border-warn/40 bg-warn-soft p-4 text-sm text-warn-ink">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                </svg>
                <p>
                    <strong class="font-bold">Нужна калибровка камеры.</strong>
                    После замены стекла камеру систем помощи водителю обязательно калибруют —
                    иначе она ошибается в разметке и дистанции. Учтите это в стоимости работ.
                </p>
            </div>
        @endif

        @php
            $specs = array_filter([
                'Тип стекла'  => $product->glass_type?->label(),
                'Еврокод'     => $product->eurocode,
                'Код OEM'     => $product->oem_code,
                'Артикул'     => $product->internal_code,
                'Ширина'      => $product->width_mm ? $product->width_mm.' мм' : null,
                'Высота'      => $product->height_mm ? $product->height_mm.' мм' : null,
                'Тонировка'   => match ($product->tint) {
                    'green' => 'Зелёная', 'grey' => 'Серая', 'blue' => 'Голубая',
                    'bronze' => 'Бронзовая', 'clear' => 'Без тонировки', 'tinted' => 'Тонированное',
                    default => null,
                },
            ]);
        @endphp

        @if ($specs)
            <section class="mt-8">
                <h2 class="text-lg font-bold">Характеристики</h2>
                <dl class="mt-3 grid gap-x-8 sm:grid-cols-2">
                    @foreach ($specs as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-line py-2.5 text-sm">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="text-right font-semibold">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($product->fitments->isNotEmpty())
            <section class="mt-8">
                <h2 class="text-lg font-bold">Подходит к автомобилям</h2>
                <p class="mt-1 text-sm text-muted">
                    Одно стекло встаёт на несколько машин — проверьте свою в списке.
                </p>

                <ul class="mt-3 grid gap-x-8 sm:grid-cols-2">
                    @foreach ($product->fitments as $fitment)
                        <li class="border-b border-line">
                            <a href="{{ route('catalog.products', [$fitment->carModel->brand, $fitment->carModel]) }}"
                               class="flex items-baseline justify-between gap-3 py-2.5 text-sm transition hover:text-brand">
                                <span class="font-medium">
                                    {{ $fitment->carModel->brand->name }} {{ $fitment->carModel->name }}
                                </span>
                                @if ($fitment->year_from)
                                    <span class="shrink-0 text-xs whitespace-nowrap text-faint">
                                        {{ $fitment->year_from }}–{{ $fitment->year_to ?: 'н.в.' }}
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- Десктоп: блок заказа едет за прокруткой. Телефон: он же внизу экрана. --}}
    <aside class="lg:sticky lg:top-28 lg:self-start">
        <div class="rounded-card border border-line bg-surface p-5 shadow-card">
            @if ($product->price_min)
                <div class="font-display text-3xl leading-none font-extrabold tracking-tight">
                    {{ number_format((float) $product->price_min, 0, ',', ' ') }} ₸
                </div>
                @if ($product->price_max > $product->price_min)
                    <p class="mt-1.5 text-sm text-muted">
                        до {{ number_format((float) $product->price_max, 0, ',', ' ') }} ₸ — зависит от производителя
                    </p>
                @endif
            @else
                <div class="font-display text-xl font-bold text-muted">Цена по запросу</div>
            @endif

            <div class="mt-3 flex items-center gap-2 text-sm font-semibold">
                <span class="h-2 w-2 rounded-full {{ $product->in_stock ? 'bg-brand' : 'bg-line-strong' }}"></span>
                {{ $product->in_stock ? 'Есть в наличии' : 'Под заказ' }}
            </div>

            <form method="post" action="{{ route('cart.add', $product) }}" class="mt-4">
                @csrf
                <div class="flex gap-2">
                    <input type="number" name="quantity" value="1" min="1" max="{{ App\Cart\Cart::MAX_QUANTITY }}"
                           aria-label="Количество"
                           class="w-20 rounded-xl border border-line bg-surface px-3 py-3.5 text-center text-sm
                                  focus:border-brand focus:outline-none">
                    <button class="flex-1 rounded-xl bg-brand py-3.5 font-bold text-on-brand
                                   transition hover:bg-brand-hover active:scale-[0.99]">
                        В корзину
                    </button>
                </div>
            </form>

            @if (session('cart_added'))
                <p class="mt-2 rounded-lg bg-brand-soft p-2.5 text-center text-xs font-semibold text-brand-ink">
                    Добавлено. <a href="{{ route('cart.show') }}" class="underline">Перейти в корзину</a>
                </p>
            @endif

            <a href="#zayavka"
               class="mt-2 block w-full rounded-xl border border-line py-3 text-center font-semibold
                      transition hover:bg-sunken">
                Оставить заявку на это стекло
            </a>

            <a href="tel:{{ config('company.phones.0.tel') }}"
               class="mt-2 block w-full rounded-xl border border-line py-3 text-center font-semibold
                      transition hover:bg-sunken">
                Позвонить
            </a>

            <p class="mt-3 text-center text-xs text-faint">Менеджер подтвердит наличие и срок поставки</p>
        </div>

        @if ($product->offers->isNotEmpty())
            <div class="mt-4 rounded-card border border-line bg-surface p-5">
                <h2 class="font-bold">Предложения поставщиков</h2>
                <p class="mt-1 text-xs text-muted">Одно стекло, разные производители и гарантия</p>

                <ul class="mt-3.5 space-y-1">
                    @foreach ($product->offers->sortBy('price_retail') as $offer)
                        <li class="flex items-center justify-between gap-3 rounded-xl px-2.5 py-2.5
                                   {{ $loop->first ? 'bg-brand-soft' : '' }}">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-semibold">{{ $offer->supplier->name }}</span>
                                    @if ($loop->first)
                                        <span class="shrink-0 rounded-pill bg-brand px-2 py-0.5 text-[10px]
                                                     leading-none font-bold text-on-brand">
                                            дешевле всего
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs text-faint">
                                    {{ $offer->supplier->tierLabel() }}
                                    @if ($offer->warranty_months)
                                        · гарантия {{ $offer->warranty_months }} мес.
                                    @endif
                                    {{-- Показываем свободный остаток, а не складской:
                                         обещать то, что уже отложено под чужой заказ, — обман --}}
                                    @php($available = $offer->available())
                                    @if ($available > 0)
                                        · <span class="font-semibold text-brand-ink">в наличии {{ $available }} шт</span>
                                    @elseif ($offer->stock_qty > 0)
                                        · <span class="text-faint">всё в резерве, привезём под заказ</span>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-sm font-bold whitespace-nowrap">
                                {{ $offer->price_retail
                                    ? number_format((float) $offer->price_retail, 0, ',', ' ').' ₸'
                                    : '—' }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>

<section id="zayavka" class="mt-10 scroll-mt-28 sm:mt-14">
    <div class="mx-auto max-w-2xl rounded-card border border-line bg-surface p-5 shadow-card sm:p-7">
        <h2 class="text-xl font-bold">Оставьте заявку на это стекло</h2>
        <p class="mt-1 mb-5 text-sm text-muted">
            {{ $product->name }}
            @if ($product->price_min)
                — от {{ number_format((float) $product->price_min, 0, ',', ' ') }} ₸
            @endif
        </p>

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-warn/40 bg-warn-soft p-4 text-sm text-warn-ink">
                Проверьте поля формы — что-то заполнено не так.
            </div>
        @endif

        <x-order-form :action="route('order.store', $product)" />
    </div>
</section>

@if ($related->isNotEmpty())
    <section class="mt-10 sm:mt-14">
        <h2 class="text-xl font-bold sm:text-2xl">Другие стёкла для этой машины</h2>
        <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6">
            @foreach ($related as $item)
                <x-product-card :product="$item" />
            @endforeach
        </div>
    </section>
@endif

{{-- Нижняя панель на телефоне: цена и действие всегда под большим пальцем. --}}
<div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:hidden">
    <div class="flex items-center gap-3">
        <div class="min-w-0 flex-1">
            @if ($product->price_min)
                <div class="font-display text-lg leading-none font-extrabold">
                    {{ number_format((float) $product->price_min, 0, ',', ' ') }} ₸
                </div>
                <div class="mt-0.5 truncate text-xs text-faint">{{ $product->glass_type?->label() }}</div>
            @else
                <div class="text-sm font-bold text-muted">Цена по запросу</div>
            @endif
        </div>

        <form method="post" action="{{ route('cart.add', $product) }}" class="shrink-0">
            @csrf
            <input type="hidden" name="go_to_cart" value="1">
            <button class="rounded-xl bg-brand px-6 py-3 text-sm font-bold text-on-brand active:scale-[0.98]">
                В корзину
            </button>
        </form>
    </div>
</div>

{{-- чтобы панель не перекрывала последний блок страницы --}}
<div class="h-20 lg:hidden"></div>

@endsection
