@extends('layouts.app')

@section('title', $payment->status->isPaid() ? 'Оплата прошла' : 'Оплата не прошла')

@section('content')

@php $paid = $payment->status->isPaid(); @endphp

<div class="mx-auto max-w-lg py-10 text-center">
    <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl
                 {{ $paid ? 'bg-ok-soft text-ok-ink' : 'bg-warn-soft text-warn-ink' }}">
        @if ($paid)
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
            </svg>
        @else
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
            </svg>
        @endif
    </span>

    <h1 class="mt-5 text-2xl font-extrabold sm:text-3xl">
        {{ $paid ? 'Оплата прошла' : $payment->status->label() }}
    </h1>

    <p class="mt-2 text-muted">
        Заказ <span class="font-display font-extrabold text-ink">{{ $payment->order->number }}</span>
        на {{ number_format((float) $payment->amount, 2, ',', ' ') }} ₸
    </p>

    @if ($paid)
        <div class="card mt-6 p-5 text-left text-sm text-muted">
            @if ($payment->order->purchase_state === 'paid_review')
                Оплата получена. Менеджер проверяет возможность исполнения заказа и свяжется с вами.
            @elseif ($payment->order->purchase_draft_id)
                Оплата получена. Состав, получение и документы доступны на странице заказа.
            @elseif (settings()->enabled('display_stock') && settings()->enabled('reservations'))
                Стекло зарезервировано за вами. Менеджер свяжется, чтобы согласовать
                время замены или доставки — отдельно платить за работу при получении не придётся,
                он назовёт итог заранее.
            @else
                Оплата получена. Менеджер проверит наличие у поставщиков и свяжется,
                чтобы согласовать время замены или доставки.
            @endif
        </div>
    @else
        <div class="card mt-6 border-warn/40 bg-warn-soft p-5 text-left text-sm text-warn-ink">
            @if ($payment->failure_reason)
                {{ $payment->failure_reason }}.
            @endif
            Деньги не списаны. Можно попробовать ещё раз или оплатить при получении —
            заявка в силе, менеджер всё равно перезвонит.
        </div>
    @endif

    <div class="mt-6 flex flex-wrap justify-center gap-3">
        @if ($payment->order->purchase_draft_id)
            <a href="{{ route('purchase.show', $payment->order->public_token) }}" class="btn btn-primary">Мой заказ</a>
        @endif
        @if (! $paid && settings()->enabled('payments') && session('last_order_number') === $payment->order->number)
            <form method="post" action="{{ route('payment.start') }}">
                @csrf
                <button type="submit" class="btn btn-primary">Попробовать снова</button>
            </form>
        @endif
        <a href="{{ route('catalog.brands') }}" class="btn {{ $paid ? 'btn-primary' : 'btn-outline' }}">В каталог</a>
        <a href="tel:{{ settings()->phones()[0]['tel'] ?? config('company.phones.0.tel') }}"
           class="btn btn-outline">
            Позвонить
        </a>
    </div>
</div>

@endsection
