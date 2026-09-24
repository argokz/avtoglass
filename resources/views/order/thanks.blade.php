@extends('layouts.app')

@section('title', 'Заявка принята')

@section('content')

@php
    $wa = $order?->whatsappUrl();
@endphp

<div class="mx-auto max-w-lg py-8 sm:py-10">
    <div class="text-center">
        <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-ok-soft text-ok-ink">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
            </svg>
        </span>

        <h1 class="mt-5 text-2xl font-extrabold sm:text-3xl">Заявка принята</h1>

        @if ($number)
            <p class="mt-2 text-muted">
                Номер заявки — <span class="font-display font-extrabold text-ink">{{ $number }}</span>.
                Назовите его, если будете звонить сами.
            </p>
        @else
            <p class="mt-2 text-muted">Менеджер свяжется с вами в ближайшее время.</p>
        @endif
    </div>

    @if (session('order_deduped'))
        <div class="card mt-5 border-accent/30 bg-accent-soft p-4 text-sm text-accent-ink">
            Эта заявка уже отправлена — вот её номер, вторую создавать не стали.
        </div>
    @endif

    @if (session('payment_error'))
        <div class="card mt-5 border-warn/40 bg-warn-soft p-4 text-sm text-warn-ink">
            {{ session('payment_error') }}
        </div>
    @endif

    @if ($order?->fulfillment || $order?->payment_method)
        <div class="card mt-5 space-y-1 p-4 text-sm">
            @if ($order->fulfillment)
                <p><span class="text-muted">Получение:</span> <span class="font-semibold">{{ $order->fulfillment->label() }}</span></p>
            @endif
            @if ($order->address)
                <p><span class="text-muted">Адрес:</span> {{ $order->address }}</p>
            @endif
            @if ($order->payment_method)
                <p><span class="text-muted">Оплата:</span> <span class="font-semibold">{{ $order->payment_method->label() }}</span></p>
            @endif
        </div>
    @endif

    @if ($wa)
        <a href="{{ $wa }}" target="_blank" rel="noopener"
           class="btn btn-whatsapp btn-lg mt-5 w-full !rounded-2xl whitespace-normal">
            Отправить заказ в WhatsApp
        </a>
        <p class="mt-2 text-center text-xs leading-relaxed text-muted">
            Откроется чат с текстом заявки — нажмите «отправить», и смена увидит заказ сразу.
        </p>
    @endif

    @if ($order?->payment_method === App\Enums\PaymentMethod::Cash)
        <p class="card mt-5 p-4 text-sm leading-relaxed text-muted">
            Оплата при получении: наличными или переводом, когда привезём стекло или поставим его.
        </p>
    @elseif ($payable && settings()->enabled('payments'))
        <div class="card mt-6 border-brand/30 bg-brand-soft p-6 text-center">
            <div class="price price-brand price-lg">
                {{ number_format((float) $amount, 0, ',', ' ') }} ₸
            </div>
            <p class="mt-2 text-sm text-brand-ink">
                @if (settings()->enabled('display_stock') && settings()->enabled('reservations'))
                    Оплатите картой — стекло сразу зарезервируем
                @else
                    Оплата через Halyk Pay. На тестовом терминале деньги не списываются.
                @endif
            </p>

            <form method="post" action="{{ route('payment.start') }}" class="mt-4">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
                    Оплатить картой
                </button>
            </form>
        </div>
    @endif

    <div class="card mt-6 p-5 text-left">
        <h2 class="font-extrabold">Что дальше</h2>
        <ol class="mt-3 space-y-3 text-sm text-muted">
            <li class="flex gap-3">
                <span class="font-display text-lg font-extrabold text-brand">1</span>
                Менеджер проверит наличие стекла и перезвонит.
            </li>
            <li class="flex gap-3">
                <span class="font-display text-lg font-extrabold text-brand">2</span>
                Назовёт итоговую цену — со стеклом, работой и, если нужно, калибровкой камеры.
            </li>
            <li class="flex gap-3">
                <span class="font-display text-lg font-extrabold text-brand">3</span>
                Договоритесь о времени замены, выезда или доставки.
            </li>
        </ol>
    </div>

    @if ($order?->public_token)
        <div class="card mt-6 p-5 text-left">
            <h2 class="font-extrabold">Страница статуса</h2>
            <p class="mt-1 text-sm text-muted">
                Сохраните ссылку — номер заявки в адресе не светится, статус можно смотреть без звонка.
            </p>
            <a href="{{ route('order.track', $order) }}" class="btn btn-primary mt-4">
                Открыть статус заявки
            </a>
            <p class="mt-3 text-xs text-muted">
                Ссылку можно потерять: заявку потом найти по телефону и номеру
                <a href="{{ route('orders.lookup') }}" class="font-bold text-brand-ink underline-offset-2 hover:underline">на странице статуса</a>.
            </p>
        </div>
    @endif

    @guest
        <div class="card mt-6 p-5 text-left">
            <h2 class="font-extrabold">Чтобы не искать номер в следующий раз</h2>
            <p class="mt-1 text-sm text-muted">
                Заведите кабинет — заявки будут появляться в нём сами. На заказ это никак не влияет:
                он уже принят, менеджер перезвонит в любом случае.
            </p>
            <a href="{{ route('account.register') }}" class="btn btn-outline mt-4">
                Создать кабинет
            </a>
        </div>
    @endguest

    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="{{ route('catalog.brands') }}" class="btn btn-primary">Вернуться в каталог</a>
        <a href="tel:{{ settings()->phones()[0]['tel'] ?? config('company.phones.0.tel') }}"
           class="btn btn-outline">
            Позвонить
        </a>
    </div>
</div>

@endsection
