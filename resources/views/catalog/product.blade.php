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
    <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
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
    $ценаОт = $product->sellingPriceMin($покупатель);
    $ценаДо = $product->sellingPriceMax($покупатель);
    $опт = $покупатель?->seesWholesalePrices() === true;
@endphp

<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-10">

    <div class="min-w-0">
        @php $машина = $product->fitments->first()?->carModel; @endphp
        <div class="card relative overflow-hidden !p-0">
            <div @class(['grid', 'sm:grid-cols-[minmax(0,1fr)_9.5rem]' => (bool) $машина])>
                @if ($product->imageUrl())
                    <div class="relative aspect-video grid place-items-center bg-sunken">
                        <img src="{{ $product->imageUrl() }}" alt="{{ $product->name }}"
                             class="h-full w-full object-contain">
                    </div>
                @else
                    <x-glass-blueprint :product="$product" class="w-full min-h-[220px] sm:min-h-[280px]" />
                @endif

                @if ($машина)
                    <x-car-model-image :model="$машина" :brand="$машина->brand"
                                       class="h-28 !rounded-none !p-0 border-t border-line sm:h-full sm:border-t-0 sm:border-l" />
                @endif
            </div>

            <span class="badge badge-muted absolute top-4 left-4 z-10 !bg-night/90 !text-on-night">
                {{ $product->glass_type?->label() }}
            </span>
        </div>

        <h1 class="mt-6 text-2xl leading-tight font-extrabold sm:text-3xl">{{ $product->name }}</h1>

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
                                                   class="h-12 w-20 shrink-0 !p-1 !rounded-xl" />
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
            <details class="mt-10">
                <summary class="cursor-pointer text-sm font-bold text-muted hover:text-ink">
                    Как меняют стекло и что значат маркировки
                </summary>
                <div class="mt-4 space-y-6">
                    <x-infographics.replacement-process />
                    @if ($product->eurocode)
                        <x-infographics.glass-markings />
                    @endif
                </div>
            </details>
        @endif
    </div>

    <aside class="min-w-0 lg:sticky lg:top-28 lg:self-start">
        <div class="card p-6 shadow-lift">
                @if ($опт)
                    <p class="mb-2 text-xs font-bold tracking-wide text-brand-ink uppercase">Оптовая цена</p>
                @endif
                @if ($ценаОт)
                <div class="price price-brand price-lg" data-product-price>
                    {{ number_format($ценаОт, 0, ',', ' ') }} ₸
                </div>
                @if ($ценаДо && $ценаДо > $ценаОт)
                    <p class="mt-2 text-sm text-muted">
                        до {{ number_format($ценаДо, 0, ',', ' ') }} ₸ — зависит от производителя
                    </p>
                @endif

                @if (settings()->enabled('service_prices') && $installation)
                    <div class="mt-4 rounded-2xl bg-sunken px-4 py-3.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="text-muted">{{ $installation->name }}</span>
                            <span class="font-bold whitespace-nowrap">
                                {{ number_format((float) $installation->price, 0, ',', ' ') }} ₸
                            </span>
                        </div>
                        <div class="mt-2.5 flex items-baseline justify-between gap-3 border-t border-line pt-2.5">
                            <span class="font-bold">Со стеклом и работой</span>
                            <span class="price text-xl">
                                {{ number_format($ценаОт + (float) $installation->price, 0, ',', ' ') }} ₸
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
                <div class="font-display text-xl font-extrabold text-muted">Цена по запросу</div>
            @endif

            <div class="mt-4 flex items-center gap-2 text-sm font-bold">
                @if (settings()->enabled('display_stock'))
                    <span class="h-2.5 w-2.5 rounded-full {{ $product->in_stock ? 'bg-ok' : 'bg-line-strong' }}"></span>
                    {{ $product->in_stock ? 'Есть в наличии' : 'Под заказ' }}
                @else
                    <span class="h-2.5 w-2.5 rounded-full bg-line-strong"></span>
                    Под заказ
                @endif
            </div>

            @if (settings()->enabled('cart'))
            @php
                $выбранныйОффер = $product->offers->where('is_active', true)
                    ->sortBy(fn ($offer) => $offer->sellingPrice($покупатель) ?? PHP_INT_MAX)
                    ->first();
            @endphp
            <div class="mt-5 space-y-2" data-offer-sync>
                {{-- Две отдельные формы: иначе часть браузеров и JS путают submitter --}}
                <form method="post" action="{{ route('cart.add', $product) }}">
                    @csrf
                    <input type="hidden" name="go_to_cart" value="1">
                    <input type="hidden" name="quantity" value="1">
                    <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                    <button type="submit" class="btn btn-primary w-full !rounded-xl">
                        Быстрый заказ
                    </button>
                    <p class="mt-1.5 text-center text-xs text-faint">Сразу к оформлению в корзине</p>
                </form>

                <form method="post" action="{{ route('cart.add', $product) }}" class="flex gap-2">
                    @csrf
                    <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                    <input type="number" name="quantity" value="1" min="1" max="{{ App\Cart\Cart::maxQuantity() }}"
                           aria-label="Количество"
                           class="input !w-20 !rounded-xl !px-3 !text-center">
                    <button type="submit" class="btn btn-outline flex-1 !rounded-xl">
                        В корзину
                    </button>
                </form>
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

        @if ($product->offers->isNotEmpty())
            <div class="card mt-4 p-5">
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
                                           data-price-label="{{ $ценаОффера ? number_format($ценаОффера, 0, ',', ' ').' ₸' : 'Цена по запросу' }}"
                                           @checked($loop->first)
                                           class="mt-1 accent-brand">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
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
                                            @if (settings()->enabled('display_stock'))
                                                @php($available = $offer->available())
                                                @if ($available > 0)
                                                    <span class="font-semibold text-ok-ink">в наличии {{ $available }} шт</span>
                                                @elseif ($offer->stock_qty > 0)
                                                    <span class="text-faint">под заказ</span>
                                                @endif
                                            @else
                                                <span class="text-faint">под заказ</span>
                                            @endif
                                        </span>
                                    </span>
                                </span>
                                <span class="price shrink-0 text-base">
                                    {{ $ценаОффера
                                        ? number_format($ценаОффера, 0, ',', ' ').' ₸'
                                        : '—' }}
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </aside>
</div>

@unless (settings()->enabled('cart'))
<section id="zayavka" class="reveal mt-10 scroll-mt-28 sm:mt-14">
    <div class="card mx-auto max-w-2xl p-5 shadow-lift sm:p-8">
        <h2 class="text-xl font-extrabold">Оставьте заявку на это стекло</h2>
        <p class="mt-1 mb-5 text-sm text-muted">
            {{ $product->name }}
            @if ($ценаОт)
                — от {{ number_format($ценаОт, 0, ',', ' ') }} ₸
            @endif
        </p>

        <x-order-form :action="route('order.store', $product)" />
    </div>
</section>
@endunless

@if ($related->isNotEmpty())
    <section class="reveal mt-10 sm:mt-14">
        <h2 class="text-xl font-extrabold sm:text-2xl">Другие стёкла для этой машины</h2>
        <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-6">
            @foreach ($related as $item)
                <x-product-card :product="$item" />
            @endforeach
        </div>
    </section>
@endif

{{-- Нижняя панель на телефоне --}}
<div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
    <div class="flex items-center gap-3">
        <div class="min-w-0 flex-1">
            @if ($ценаОт)
                <div class="price price-brand text-xl">
                    {{ number_format($ценаОт, 0, ',', ' ') }} ₸
                </div>
                <div class="mt-0.5 truncate text-xs text-faint">{{ $product->glass_type?->label() }}</div>
            @else
                <div class="text-sm font-bold text-muted">Цена по запросу</div>
            @endif
        </div>

        @if (settings()->enabled('cart'))
        <div class="flex shrink-0 gap-2">
            <form method="post" action="{{ route('cart.add', $product) }}">
                @csrf
                <input type="hidden" name="offer_id" value="{{ $выбранныйОффер?->id }}" data-selected-offer>
                <button type="submit" class="btn btn-outline btn-sm">В корзину</button>
            </form>
            <form method="post" action="{{ route('cart.add', $product) }}">
                @csrf
                <input type="hidden" name="go_to_cart" value="1">
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
