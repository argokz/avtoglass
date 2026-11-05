@extends('layouts.app')

@section('title', $product->name)
@section('description', "{$product->name}. Цены нескольких поставщиков, гарантия, установка и калибровка.")
@section('body_class', 'has-mobile-cta')
@if ($product->imageUrl())
    @section('og_image', url($product->imageUrl()))
@endif

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

@php
    $productSchema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'sku' => $product->eurocode ?: $product->internal_code,
        'description' => $product->name,
        'image' => $product->imageUrl() ? url($product->imageUrl()) : null,
        'offers' => [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'KZT',
            'lowPrice' => $product->price_min,
            'highPrice' => $product->price_max ?: $product->price_min,
            'availability' => settings()->enabled('display_stock') && $product->in_stock
                ? 'https://schema.org/InStock'
                : 'https://schema.org/PreOrder',
        ],
    ]);
@endphp
@push('jsonld')
    <script type="application/ld+json">{!! \Illuminate\Support\Js::encode($productSchema) !!}</script>
@endpush

@if (session('cart_added'))
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            window.toast('Добавлено в корзину', 'ok');
        });
    </script>
    @endpush
@endif

@php
    $покупатель = текущийПокупатель();
    $offerPrices = $product->offers->map(fn ($offer) => $offer->sellingPrice($покупатель))->filter(fn ($price) => $price !== null);
    $ценаОт = $product->offers->isNotEmpty() ? $offerPrices->min() : $product->sellingPriceMin($покупатель);
    $ценаДо = $product->offers->isNotEmpty() ? $offerPrices->max() : $product->sellingPriceMax($покупатель);
    $опт = $покупатель?->seesWholesalePrices() === true;
    $выбранныйОффер = $product->offers->where('is_active', true)
        ->sortBy(fn ($offer) => $offer->sellingPrice($покупатель) ?? PHP_INT_MAX)->first();
@endphp

<div class="product-heading">
    <p class="eyebrow">{{ $product->glass_type?->label() }} · {{ $product->eurocode ?: $product->internal_code }}</p>
    <h1>{{ $product->name }}</h1>
    <p class="mt-2 text-muted">Сверьте год, кузов и опции. Совместимость подтвердим перед заказом.</p>
</div>
<div class="product-layout grid gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-10">

    <div class="min-w-0">
        @php $машина = $product->fitments->first()?->carModel; @endphp
        <div class="card relative !p-0">
            <div @class(['grid', 'sm:grid-cols-[minmax(0,1fr)_12rem]' => (bool) $машина])>
                @if ($product->image_path)
                    <div class="relative aspect-video grid place-items-center overflow-hidden bg-sunken">
                        {{-- Первый экран карточки: грузим сразу и в приоритете, это LCP-элемент --}}
                        <x-picture :path="$product->image_path" :alt="$product->name" :eager="true"
                                   sizes="(min-width: 1024px) 640px, 100vw"
                                   class="h-full w-full object-contain" />
                    </div>
                @else
                    <div class="overflow-hidden">
                        <x-glass-blueprint :product="$product" class="w-full min-h-[220px] sm:min-h-[280px]" />
                    </div>
                @endif

                @if ($машина)
                    <x-car-model-image :model="$машина" :brand="$машина->brand"
                                       class="h-28 w-full !rounded-none border-t border-line p-2 sm:h-auto sm:min-h-[12rem] sm:border-t-0 sm:border-l" />
                @endif
            </div>

            <span class="badge badge-muted absolute top-4 left-4 z-10 !bg-night/90 !text-on-night">
                {{ $product->glass_type?->label() }}
            </span>
            @if ($product->isMadeInKz())
                <span class="badge badge-ok absolute top-4 right-4 z-10">Сделано в Казахстане</span>
            @endif
        </div>

        @if ($options = $product->activeOptions())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($options as $option)
                    <span class="badge badge-accent">{{ $option }}</span>
                @endforeach
            </div>
        @endif

        @if ($product->camera)
            <div class="mt-5 flex gap-3.5 rounded-card border border-warn/40 bg-warn-soft p-4 text-sm text-warn-ink">
                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
                'Отверстий'   => $product->holes_count ?: null,
                'Тонировка'   => match ($product->tint) {
                    'green' => 'Зелёная', 'grey' => 'Серая', 'blue' => 'Голубая',
                    'bronze' => 'Бронзовая', 'clear' => 'Без тонировки', 'tinted' => 'Тонированное',
                    default => null,
                },
            ]);
        @endphp

        @if ($specs)
            <section class="reveal mt-8">
                <h2 class="text-lg font-extrabold">Характеристики</h2>
                <dl class="card mt-3 grid gap-x-8 !p-0 sm:grid-cols-2 sm:divide-x sm:divide-line">
                    @foreach ($specs as $label => $value)
                        <div class="flex items-baseline justify-between gap-3 border-b border-line px-4 py-3 text-sm last:border-b-0 sm:odd:pr-5 sm:even:pl-5">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="text-right font-bold">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($product->fitments->isNotEmpty())
            <section class="reveal mt-8">
                <h2 class="text-lg font-extrabold">Подходит к автомобилям</h2>
                <p class="mt-1 text-sm text-muted">
                    Одно стекло встаёт на несколько машин — проверьте свою в списке.
                </p>

                <ul class="card mt-3 grid !p-0 sm:grid-cols-2 sm:divide-x sm:divide-line">
                    @foreach ($product->fitments as $fitment)
                        <li class="border-b border-line last:border-b-0">
                            <a href="{{ route('catalog.products', [$fitment->carModel->brand, $fitment->carModel]) }}"
                               class="flex items-center gap-3 px-3 py-2.5 text-sm transition hover:bg-sunken hover:text-brand-ink">
                                <x-car-model-image :model="$fitment->carModel" :brand="$fitment->carModel->brand"
                                                   class="h-14 w-24 shrink-0 !p-1.5 !rounded-xl" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold">
                                        {{ $fitment->carModel->brand->name }} {{ $fitment->carModel->name }}
                                    </span>
                                    @if ($fitment->year_from)
                                        <span class="text-xs text-faint">
                                            {{ $fitment->year_from }}–{{ $fitment->year_to ?: 'н.в.' }}
                                        </span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($product->glass_type?->isGlass())
            <section class="reveal cv-auto mt-10 space-y-6">
                <x-infographics.supplier-tiers />
                <x-infographics.replacement-process />
                @if ($product->eurocode)
                    <x-infographics.glass-markings :product="$product" />
                @endif
            </section>
        @endif
    </div>

    <aside class="product-purchase min-w-0 lg:sticky lg:top-28 lg:self-start" aria-label="Выбор и покупка стекла">
        <div class="card p-6 shadow-lift purchase-panel">
            <p class="eyebrow mb-3">СТОИМОСТЬ СТЕКЛА</p>
                @if ($опт)
                    <p class="mb-2 text-xs font-bold tracking-wide text-brand-ink uppercase">Оптовая цена</p>
                @endif
                @if ($ценаОт)
                <div class="price price-brand price-lg" data-product-price>
                    {{ formatPrice($ценаОт) }} ₸
                </div>
                @if ($ценаДо && $ценаДо > $ценаОт)
                    <p class="mt-2 text-sm text-muted">
                        до {{ formatPrice($ценаДо) }} ₸ — зависит от производителя
                    </p>
                @endif

                @if (settings()->enabled('service_prices') && $installation)
                    <div class="mt-4 rounded-2xl bg-sunken px-4 py-3.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-muted">{{ $installation->name }}</span>
                            <span class="font-bold whitespace-nowrap">
                                {{ formatPrice((float) $installation->price) }} ₸
                            </span>
                        </div>
                        @if ($calibration)
                            <div class="mt-1.5 flex items-baseline justify-between gap-3 text-xs">
                                <span class="text-muted">{{ $calibration->name }} (при необходимости)</span>
                                <span class="font-semibold whitespace-nowrap text-muted">
                                    + {{ formatPrice((float) $calibration->price) }} ₸
                                </span>
                            </div>
                        @endif
                        <div class="mt-2.5 flex items-baseline justify-between gap-3 border-t border-line pt-2.5">
                            <span class="font-bold">Со стеклом и работой</span>
                            <span class="price text-xl" data-product-total>
                                {{ formatPrice($ценаОт + (float) $installation->price) }} ₸
                            </span>
                        </div>

                        @if (settings()->enabled('booking'))
                        <a href="{{ route('booking.form', $product) }}" wire:navigate
                           class="mt-2.5 block text-center text-sm font-bold text-brand-ink underline-offset-4 hover:underline">
                            Выбрать время замены
                        </a>
                        @endif
                    </div>
                @elseif (settings()->enabled('booking'))
                    <a href="{{ route('booking.form', $product) }}" wire:navigate
                       class="mt-3 block text-center text-sm font-bold text-brand-ink underline-offset-4 hover:underline">
                        Выбрать время замены
                    </a>
                @endif
            @else
                <div class="font-display text-xl font-extrabold text-muted" data-product-price>Цена по запросу</div>
            @endif

            {{-- Срок первого (самого дешёвого) предложения; при выборе другого его подменяет app.js. --}}
            @php
                $сроки = $product->offers->mapWithKeys(fn ($offer) => [$offer->id => App\Domain\Catalog\Availability::forOffer($offer)]);
                $первыйСрок = $product->offers->sortBy(fn ($offer) => $offer->sellingPrice($покупатель) ?? PHP_INT_MAX)->first();
                $первыйСрок = $первыйСрок ? $сроки[$первыйСрок->id] : App\Domain\Catalog\Availability::forProductFlag((bool) $product->in_stock);
            @endphp
            <div class="mt-4 flex items-center gap-2 text-sm font-bold" data-product-stock>
                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $первыйСрок->state === 'in_stock' ? 'bg-ok' : 'bg-line-strong' }}"></span>
                {{ $первыйСрок->label() }}
            </div>

        @if ($product->offers->isNotEmpty())
            <div class="mt-5 border-t border-line pt-5">
                <h2 class="font-extrabold">Предложения поставщиков</h2>
                <p class="mt-1 text-xs text-muted">Одно стекло, разные производители и гарантия. Выберите вариант — он попадёт в заказ.</p>

                <ul class="mt-4 space-y-2" data-offer-list>
                    @foreach ($product->offers->sortBy(fn ($offer) => $offer->sellingPrice($покупатель) ?? PHP_INT_MAX) as $offer)
                        @php $ценаОффера = $offer->sellingPrice($покупатель); @endphp
                        <li>
                            <label class="flex cursor-pointer items-center justify-between gap-3 rounded-2xl border border-line p-3.5 transition hover:border-brand/30
                                       has-checked:border-brand has-checked:bg-brand-soft/40
                                       {{ $loop->first ? 'border-brand/25 bg-brand-soft/40' : 'bg-surface' }}">
                                <span class="flex min-w-0 items-start gap-3">
                                    <input type="radio" name="picked_offer" value="{{ $offer->id }}"
                                           data-price="{{ (float) ($ценаОффера ?? 0) }}"
                                           data-total-label="{{ $ценаОффера !== null && $installation ? formatPrice($ценаОффера + (float) $installation->price).' ₸' : 'Уточним стоимость' }}"
                                           data-stock-label="{{ $сроки[$offer->id]->label() }}"
                                           data-price-label="{{ $ценаОффера ? formatPrice($ценаОффера).' ₸' : 'Цена по запросу' }}"
                                           data-supplier-label="{{ $offer->supplier->name }} · {{ $product->glass_type?->label() }}"
                                           @checked($loop->first)
                                           class="mt-1 accent-brand">
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <x-supplier-logo :supplier="$offer->supplier" />
                                            @if ($loop->first)
                                                <span class="badge badge-brand">лучшая цена</span>
                                            @endif
                                        </span>
                                        <span class="mt-1.5 block text-xs text-faint">
                                            @if ($offer->supplier->country)
                                                <span class="font-semibold text-ink">{{ $offer->supplier->country }}</span> ·
                                            @endif
                                            @if ($offer->warranty_months)
                                                гарантия {{ $offer->warranty_months }} мес. ·
                                            @endif
                                            <span @class(['font-semibold text-ok-ink' => $сроки[$offer->id]->state === 'in_stock', 'text-faint' => $сроки[$offer->id]->state !== 'in_stock'])>{{ $сроки[$offer->id]->state === 'in_stock' ? 'в наличии' : 'под заказ · '.$сроки[$offer->id]->short() }}</span>
                                        </span>
                                    </span>
                                </span>
                                <span class="price shrink-0 text-base">
                                    {{ $ценаОффера
                                        ? formatPrice($ценаОффера).' ₸'
                                        : '—' }}
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
            @if (settings()->enabled('cart'))
            <div class="mt-5 space-y-3" data-offer-sync>
                {{-- 1. Основное действие: сразу перейти к оформлению заказа --}}
                <form method="post" action="{{ route('cart.add', $product) }}">
                    @csrf
                    <input type="hidden" name="go_to_cart" value="1">
                    <input type="hidden" name="quantity" value="1" data-product-mobile-quantity>
                    <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                    <button type="submit" class="btn btn-primary btn-lg w-full !rounded-xl">
                        Оформить · Быстрый заказ
                    </button>
                    <p class="mt-1.5 text-center text-xs text-faint">Сразу к оформлению в корзине — выбор установки или доставки</p>
                </form>

                {{-- 2. Вторичные действия: положить в корзину с выбором количества или 1 клик по телефону --}}
                <div class="space-y-2 border-t border-line pt-3">
                    <form method="post" action="{{ route('cart.add', $product) }}" class="flex gap-2" data-product-quantity-form>
                        @csrf
                        <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                        <div class="qty-stepper shrink-0" data-qty-stepper>
                            <button type="button" data-qty="-1" aria-label="Меньше">−</button>
                            <input type="number" name="quantity" value="1" min="1" max="{{ App\Cart\Cart::maxQuantity() }}"
                                   aria-label="Количество">
                            <button type="button" data-qty="1" aria-label="Больше">+</button>
                        </div>
                        <button type="submit" class="btn btn-outline flex-1 !rounded-xl">
                            В корзину
                        </button>
                    </form>

                    <button type="button"
                            data-quick-order="{{ route('order.quick', $product) }}"
                            data-quick-order-name="{{ $product->name }}"
                            class="btn btn-outline w-full !rounded-xl">
                        Купить в 1 клик
                    </button>
                    <p class="text-center text-xs text-faint">Только телефон — остальное уточнит менеджер</p>
                </div>
            </div>
            @else
            <a href="#zayavka" class="btn btn-outline mt-5 w-full !rounded-xl">
                Оставить заявку на это стекло
            </a>
            @endif

            <a href="tel:{{ settings()->phones()[0]['tel'] ?? config('company.phones.0.tel') }}"
               class="btn btn-ghost mt-1 w-full">
                Позвонить
            </a>

            <p class="mt-3 text-center text-xs text-faint">Менеджер подтвердит наличие и срок поставки</p>
        </div>

    </aside>
</div>

@unless (settings()->enabled('cart'))
<section id="zayavka" class="reveal mt-10 scroll-mt-28 sm:mt-14">
    <div class="card mx-auto max-w-2xl p-5 shadow-lift sm:p-8">
        <h2 class="text-xl font-extrabold">Оставьте заявку на это стекло</h2>
        <p class="mt-1 mb-5 text-sm text-muted">
            {{ $product->name }}
            @if ($ценаОт)
                — от {{ formatPrice($ценаОт) }} ₸
            @endif
        </p>

        <x-order-form :action="route('order.store', $product)" />
    </div>
</section>
@endunless

@if ($consumables->isNotEmpty())
    <section class="reveal cv-auto mt-10 sm:mt-14">
        <h2 class="text-xl font-extrabold sm:text-2xl">С этим покупают</h2>
        <p class="mt-1 text-sm text-muted">Молдинги, клей и расходники для замены стекла.</p>
        <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @foreach ($consumables as $item)
                <x-product-card :product="$item" />
            @endforeach
        </div>
    </section>
@endif

@if ($related->isNotEmpty())
    <section class="reveal cv-auto mt-10 sm:mt-14">
        <h2 class="text-xl font-extrabold sm:text-2xl">Другие стёкла для этой машины</h2>
        <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6">
            @foreach ($related as $item)
                <x-product-card :product="$item" />
            @endforeach
        </div>
    </section>
@endif

{{-- Нижняя панель на телефоне --}}
<div data-product-mobile-bar class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
    <div class="flex items-center gap-3">
        <div class="min-w-0 flex-1">
            @if ($ценаОт)
                <div class="price price-brand text-xl" data-product-price>
                    {{ formatPrice($ценаОт) }} ₸
                </div>
                <div class="mt-0.5 truncate text-xs text-faint" data-product-supplier>
                    {{ $выбранныйОффер?->supplier?->name ? $выбранныйОффер->supplier->name.' · ' : '' }}{{ $product->glass_type?->label() }}
                </div>
            @else
                <div class="text-sm font-bold text-muted" data-product-price>Цена по запросу</div>
            @endif
        </div>

        @if (settings()->enabled('cart'))
        <div class="flex shrink-0 items-center gap-2">
            <button type="button"
                    data-quick-order="{{ route('order.quick', $product) }}"
                    data-quick-order-name="{{ $product->name }}"
                    class="btn btn-outline btn-sm !px-3"
                    aria-label="Купить в 1 клик">
                В 1 клик
            </button>
            <form method="post" action="{{ route('cart.add', $product) }}">
                @csrf
                <input type="hidden" name="go_to_cart" value="1">
                <input type="hidden" name="quantity" value="1" data-product-mobile-quantity>
                <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                <button type="submit" class="btn btn-primary">Оформить</button>
            </form>
        </div>
        @else
            <a href="#zayavka" class="btn btn-primary">Заявка</a>
        @endif
    </div>
</div>

<div class="h-28 lg:hidden"></div>

@endsection
