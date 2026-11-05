@extends('layouts.app')

@section('title', settings()->brandName().' — подбор стекла в чате')
@section('description', 'Напишите марку и модель — помощник подберёт стекло, назовёт цену и оформит заявку.')
@section('body_class', 'is-chat-home')

@section('content')

<div class="grid min-h-0 flex-1 gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
    <aside class="assistant-guide hidden rounded-card border border-line bg-surface p-5 lg:block" aria-label="Разделы помощника">
        <p class="text-xs font-bold tracking-wider text-muted uppercase">Ваш подбор</p>
        <p class="mt-3 text-sm leading-relaxed text-muted">Опишите автомобиль и нужное стекло. Помощник уточнит детали и предложит варианты.</p>
        <nav class="mt-6 space-y-2" aria-label="Заказы и каталог">
            <a href="{{ route('catalog.brands') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Открыть каталог <span class="ml-auto" aria-hidden="true">↗</span></a>
            @if (settings()->enabled('cart'))
                <a href="{{ route('cart.show') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Выбранные товары <span class="ml-auto text-muted">{{ app(App\Cart\Cart::class)->count() }}</span></a>
            @endif
            <a href="{{ auth()->check() && ! auth()->user()->isStaff() ? route('account.orders') : route('orders.lookup') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Мои заказы</a>
            @if (текущийПокупатель()?->seesWholesalePrices())
                <a href="{{ route('wholesale.index') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Оптовая закупка</a>
            @endif
        </nav>
        <div class="mt-8 border-t border-line pt-5 text-sm leading-relaxed text-muted">
            Точное наличие и совместимость подтвердит менеджер. Товары в корзине сохраняются при переходе в магазин.
        </div>
    </aside>
    <section class="flex min-w-0 flex-col" aria-labelledby="assistant-title">
        <div class="page-heading mb-4">
            <h1 id="assistant-title" class="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">Подберём стекло вместе</h1>
            <p class="mt-2 text-sm text-muted sm:text-base">Начните с марки, модели и года. Например: «Camry 2018, нужно лобовое».</p>
        </div>
        <livewire:ai-chat :embedded="true" />
    </section>
</div>

@endsection
