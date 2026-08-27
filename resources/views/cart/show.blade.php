@extends('layouts.app')

@section('title', 'Корзина')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Корзина</span>
@endsection

@section('content')

<h1 class="text-2xl font-bold sm:text-3xl">Корзина</h1>

@if ($items->isEmpty())
    <div class="mt-8 rounded-card border border-line bg-surface px-6 py-14 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-line-strong">
            <x-glass-icon type="windshield" class="h-7 w-7" />
        </span>
        <p class="mt-4 font-display font-bold">Пока пусто</p>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-muted">
            Выберите стекло по марке и модели — или опишите машину, и мы подберём сами.
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-3">
            <a href="{{ route('catalog.brands') }}"
               class="rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-hover">
                Открыть каталог
            </a>
            <a href="{{ route('order.form') }}"
               class="rounded-pill border border-line px-6 py-2.5 text-sm font-semibold transition hover:bg-sunken">
                Заявка на подбор
            </a>
        </div>
    </div>
@else
    <p class="mt-1.5 text-sm text-muted">Позиций: {{ $items->count() }}</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:gap-10">

        <div class="space-y-3">
            @foreach ($items as $item)
                <div class="flex gap-4 rounded-card border border-line bg-surface p-4">
                    <a href="{{ route('catalog.product', $item->product) }}"
                       class="grid h-20 w-24 shrink-0 place-items-center rounded-xl bg-sunken p-3 text-line-strong">
                        <x-glass-icon :type="$item->product->glass_type" class="h-full w-full" />
                    </a>

                    <div class="min-w-0 flex-1">
                        <a href="{{ route('catalog.product', $item->product) }}"
                           class="text-sm leading-snug font-semibold transition hover:text-brand">
                            {{ $item->product->name }}
                        </a>

                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-faint">
                            <span class="{{ $item->product->in_stock ? 'font-semibold text-brand-ink' : '' }}">
                                {{ $item->product->in_stock ? 'В наличии' : 'Под заказ' }}
                            </span>
                            @if ($item->hasPrice())
                                <span>· {{ number_format($item->price(), 0, ',', ' ') }} ₸ за шт</span>
                            @endif
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            {{-- Количество меняется отправкой формы: без JS корзина
                                 должна оставаться рабочей. --}}
                            <form method="post" action="{{ route('cart.update', $item->product) }}"
                                  class="flex items-center gap-2">
                                @csrf
                                @method('patch')
                                <label class="text-xs text-muted" for="qty-{{ $item->product->id }}">Кол-во</label>
                                <input id="qty-{{ $item->product->id }}" type="number" name="quantity"
                                       value="{{ $item->quantity }}" min="1" max="{{ App\Cart\Cart::MAX_QUANTITY }}"
                                       onchange="this.form.submit()"
                                       class="w-20 rounded-lg border border-line bg-surface px-3 py-1.5 text-sm
                                              focus:border-brand focus:outline-none">
                                <noscript>
                                    <button class="rounded-lg border border-line px-3 py-1.5 text-sm">ОК</button>
                                </noscript>
                            </form>

                            <div class="flex items-center gap-3">
                                @if ($item->hasPrice())
                                    <span class="font-display font-extrabold">
                                        {{ number_format($item->subtotal(), 0, ',', ' ') }} ₸
                                    </span>
                                @else
                                    <span class="text-sm text-faint">Цена по запросу</span>
                                @endif

                                <form method="post" action="{{ route('cart.remove', $item->product) }}">
                                    @csrf
                                    @method('delete')
                                    <button class="rounded-lg px-2 py-1 text-sm text-faint transition hover:text-warn-ink"
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
            <div class="rounded-card border border-line bg-surface p-5 shadow-card">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm text-muted">Итого</span>
                    <span class="font-display text-2xl font-extrabold tracking-tight">
                        {{ number_format($total, 0, ',', ' ') }} ₸
                    </span>
                </div>

                @if ($hasItemsWithoutPrice)
                    {{-- Часть позиций идёт «по запросу»: обещать по ним сумму нельзя --}}
                    <p class="mt-2 rounded-lg bg-warn-soft p-2.5 text-xs text-warn-ink">
                        По некоторым позициям цена уточняется — итог назовёт менеджер.
                    </p>
                @endif

                <p class="mt-2 text-xs text-faint">
                    Стоимость работ считается отдельно и зависит от машины.
                </p>
            </div>

            <div class="mt-4 rounded-card border border-line bg-surface p-5">
                <h2 class="font-bold">Оформить заявку</h2>
                <p class="mt-1 mb-4 text-sm text-muted">Менеджер подтвердит наличие по каждой позиции</p>

                @if ($errors->any())
                    <div class="mb-4 rounded-xl border border-warn/40 bg-warn-soft p-3 text-sm text-warn-ink">
                        Проверьте поля формы.
                    </div>
                @endif

                <x-order-form :action="route('cart.checkout')" submit="Оформить заявку" />
            </div>
        </aside>
    </div>
@endif

@endsection
