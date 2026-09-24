@extends('layouts.app')

@section('title', 'Корзина')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Корзина</span>
@endsection

@section('content')

<h1 class="text-2xl font-extrabold sm:text-3xl">Корзина</h1>

@if ($items->isEmpty())
    <div class="card mt-8 px-6 py-14 text-center">
        <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-sunken text-line-strong">
            <x-glass-icon type="windshield" class="h-8 w-8" />
        </span>
        <p class="mt-4 font-display text-lg font-extrabold">Пока пусто</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            Выберите стекло по марке и модели — или опишите машину, и мы подберём сами.
        </p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('catalog.brands') }}" class="btn btn-primary">Открыть каталог</a>
            <a href="{{ route('order.form') }}" class="btn btn-outline">Заявка на подбор</a>
        </div>
    </div>
@else
    <div data-cart-quotes='@json($quotes)'>
    <ol class="mt-4 flex list-none flex-wrap gap-x-5 gap-y-2 text-sm">
        <li class="flex items-center gap-2">
            <span class="step-num">1</span>
            <span class="font-semibold">Состав</span>
        </li>
        <li class="flex items-center gap-2 text-muted">
            <span class="step-num">2</span>
            <span>Как получите и контакты</span>
        </li>
    </ol>

    @if ($carLabel)
        <p class="mt-3 text-sm text-muted">
            Стекло на <strong class="text-ink">{{ $carLabel }}</strong>
        </p>
    @endif

    <p class="mt-1.5 text-sm text-muted">Позиций: {{ $items->count() }}</p>

    @if (текущийПокупатель()?->seesWholesalePrices())
        <p class="mt-3 rounded-2xl bg-brand-soft px-4 py-3 text-sm text-brand-ink">
            Оптовые цены. Наличие и срок подтверждает менеджер.
        </p>
    @endif

    @if (session('quick_checkout'))
        <div class="mt-4 rounded-2xl border border-brand/30 bg-brand-soft px-4 py-3 text-sm text-brand-ink">
            Стекло в корзине — заполните данные ниже, и менеджер подтвердит заказ.
        </div>
    @endif

    {{-- Состав + краткий итог (как в магазинах: сначала что берёте, потом оформление) --}}
    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
        <div class="space-y-3">
            @foreach ($items as $item)
                <div class="card flex gap-4 p-4">
                    <a href="{{ route('catalog.product', $item->product) }}"
                       class="grid h-24 w-28 shrink-0 place-items-center overflow-hidden rounded-2xl bg-sunken p-3 text-line-strong">
                        @if ($item->product->imageUrl())
                            <img src="{{ $item->product->imageUrl() }}" alt="" class="h-full w-full object-contain" loading="lazy">
                        @else
                            <x-glass-icon :type="$item->product->glass_type" class="h-full w-full" />
                        @endif
                    </a>

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('catalog.product', $item->product) }}"
                           class="text-sm leading-snug font-bold transition hover:text-brand-ink">
                            {{ $item->product->name }}
                        </a>

                        <div class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-faint">
                            @if ($item->offer?->supplier)
                                <span class="font-semibold text-ink">{{ $item->offer->supplier->name }}</span>
                            @endif
                            @if (settings()->enabled('display_stock'))
                                @if ($item->product->in_stock)
                                    <span class="badge badge-ok">В наличии</span>
                                @else
                                    <span class="badge badge-muted">Под заказ</span>
                                @endif
                            @else
                                <span class="badge badge-muted">Под заказ</span>
                            @endif
                            @if ($item->hasPrice())
                                <span>{{ number_format($item->price(), 0, ',', ' ') }} ₸ за шт</span>
                            @endif
                            @if (текущийПокупатель()?->seesWholesalePrices())
                                <span class="badge badge-brand">Опт</span>
                            @endif
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <form method="post" action="{{ route('cart.update', $item->product) }}"
                                  class="qty-stepper" data-qty-stepper data-auto-submit>
                                @csrf
                                @method('patch')
                                @if ($item->offer)
                                    <input type="hidden" name="offer_id" value="{{ $item->offer->id }}">
                                @endif
                                <button type="button" data-qty="-1" aria-label="Меньше">−</button>
                                <input id="qty-{{ $item->product->id }}-{{ $item->offer?->id ?? 0 }}" type="number" name="quantity"
                                       value="{{ $item->quantity }}" min="1" max="{{ App\Cart\Cart::maxQuantity() }}"
                                       aria-label="Количество"
                                       onchange="this.form.submit()">
                                <button type="button" data-qty="1" aria-label="Больше">+</button>
                                <noscript>
                                    <button type="submit" class="btn btn-sm btn-outline !rounded-none">ОК</button>
                                </noscript>
                            </form>

                            <div class="flex items-center gap-3">
                                @if ($item->hasPrice())
                                    <span class="price text-lg">
                                        {{ number_format($item->subtotal(), 0, ',', ' ') }} ₸
                                    </span>
                                @else
                                    <span class="text-sm text-faint">Цена по запросу</span>
                                @endif

                                <form method="post" action="{{ route('cart.remove', $item->product) }}" data-no-loading>
                                    @csrf
                                    @method('delete')
                                    @if ($item->offer)
                                        <input type="hidden" name="offer_id" value="{{ $item->offer->id }}">
                                    @endif
                                    <button type="submit" class="text-sm font-semibold text-faint transition hover:text-brand-ink"
                                            aria-label="Убрать из корзины">
                                        Убрать
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <aside class="lg:sticky lg:top-28 lg:self-start">
            <x-cart-summary :quotes="$quotes" :fulfillment="old('fulfillment', $defaultFulfillment)" />
        </aside>
    </div>

    {{-- Полноширинное оформление: две колонки, кнопка отправки в сайдбаре через form= --}}
    <section id="oformlenie" class="mt-10 scroll-mt-28 sm:mt-12">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="flex items-center gap-2 text-xl font-extrabold sm:text-2xl">
                    <span class="step-num">2</span>
                    Оформление заказа
                </h2>
                <p class="mt-1 text-sm text-muted">Менеджер подтвердит наличие по каждой позиции</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
            <div class="card p-5 sm:p-6">
                <x-order-form
                    form-id="cart-checkout"
                    layout="wide"
                    :show-submit="false"
                    :with-car="true"
                    :car-info="$carLabel"
                    :action="route('cart.checkout')"
                    submit="Оформить заказ"
                />
            </div>

            <aside class="lg:sticky lg:top-28 lg:self-start space-y-3">
                <div class="card p-5">
                    <h3 class="text-sm font-extrabold">Состав</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($items as $item)
                            <li class="flex justify-between gap-3">
                                <span class="min-w-0 truncate text-muted">
                                    {{ $item->product->name }}
                                    @if ($item->offer?->supplier)
                                        · {{ $item->offer->supplier->name }}
                                    @endif
                                    @if ($item->quantity > 1)
                                        × {{ $item->quantity }}
                                    @endif
                                </span>
                                <span class="shrink-0 font-semibold">
                                    @if ($item->hasPrice())
                                        {{ number_format($item->subtotal(), 0, ',', ' ') }} ₸
                                    @else
                                        —
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <x-cart-summary
                    :quotes="$quotes"
                    :fulfillment="old('fulfillment', $defaultFulfillment)"
                    :submit="true"
                    form="cart-checkout"
                />
            </aside>
        </div>
    </section>
    </div>
    @endif

@endsection

@if ($errors->any() && ! $items->isEmpty())
@push('scripts')
<script>
    document.getElementById('oformlenie')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
</script>
@endpush
@endif
