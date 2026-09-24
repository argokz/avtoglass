@extends('layouts.app')

@section('title', 'Заявка '.$order->number)

@section('content')

<div class="mx-auto max-w-lg py-8 sm:py-10">
    <h1 class="text-center text-2xl font-extrabold sm:text-3xl">Заявка {{ $order->number }}</h1>

    @if ($order->status === App\Enums\OrderStatus::Cancelled)
        <div class="card mt-6 border-warn/40 bg-warn-soft p-5 text-center">
            <p class="font-display text-xl font-extrabold">{{ $order->status->label() }}</p>
            <p class="mt-2 text-sm text-warn-ink">{{ $order->status->clientHint() }}</p>
        </div>
    @else
        @php
            $steps = App\Enums\OrderStatus::trackPipeline();
            $current = $order->status->trackIndex();
        @endphp

        {{-- Мобильный: вертикальный степпер; с sm — горизонтальный --}}
        <ol class="card mt-6 space-y-0 p-4 sm:grid sm:grid-cols-5 sm:gap-2 sm:space-y-0 sm:!p-5" aria-label="Статус заявки">
            @foreach ($steps as $i => $step)
                <li class="flex items-center gap-3 border-b border-line py-3 last:border-b-0 sm:flex-col sm:border-b-0 sm:py-0 sm:text-center">
                    <span @class([
                        'grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-extrabold sm:mx-auto',
                        'bg-brand text-on-brand shadow-pop' => $i <= $current,
                        'bg-sunken text-faint' => $i > $current,
                    ])>{{ $i + 1 }}</span>
                    <span @class([
                        'text-sm font-bold leading-snug sm:mt-2 sm:text-xs',
                        'text-ink' => $i <= $current,
                        'text-faint' => $i > $current,
                    ])>{{ $step->label() }}</span>
                </li>
            @endforeach
        </ol>

        <p class="mt-5 text-center text-sm text-muted">{{ $order->status->clientHint() }}</p>
    @endif

    @if ($order->source === App\Enums\OrderSource::Booking && $order->scheduled_at)
        <div class="card mt-6 border-brand/30 bg-brand-soft p-5 text-center">
            <p class="text-sm font-semibold text-brand-ink">Вы записаны</p>
            <p class="mt-1 font-display text-xl font-extrabold">
                {{ $order->scheduled_at->translatedFormat('j F, H:i') }}
            </p>
            @if ($point = $order->assignments->first()?->servicePoint)
                <p class="mt-1 text-sm font-bold">{{ $point->name }}</p>
                <p class="text-sm text-muted">{{ $point->address }}</p>
            @endif
        </div>
    @endif

    @if ($order->items->isNotEmpty() || $order->isPaid())
        <ul class="card mt-6 space-y-2.5 p-5 text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3">
                    <span class="font-medium">{{ $item->name }}</span>
                    @if ($item->price)
                        <span class="price whitespace-nowrap text-base">
                            {{ number_format((float) $item->price * $item->quantity, 0, ',', ' ') }} ₸
                        </span>
                    @endif
                </li>
            @endforeach

            @if ($paid = $order->paidAmount())
                <li @class([
                    'flex justify-between gap-3 font-bold text-ok-ink',
                    'border-t border-line pt-2.5' => $order->items->isNotEmpty(),
                ])>
                    <span>Оплачено</span>
                    <span class="whitespace-nowrap">{{ number_format($paid, 0, ',', ' ') }} ₸</span>
                </li>
            @endif
        </ul>
    @endif

    <p class="mt-6 text-center text-sm text-muted">
        Сохраните эту страницу — по ней можно узнать статус заявки, не называя номер.
        Если ссылку потеряете, заявку можно найти
        <a href="{{ route('orders.lookup') }}" class="font-bold text-brand-ink underline-offset-2 hover:underline">по телефону и номеру</a>.
    </p>

    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="tel:{{ settings()->phones()[0]['tel'] ?? '' }}" class="btn btn-primary">Позвонить</a>
        @if (settings()->enabled('whatsapp') && settings()->whatsapp())
            <a href="https://wa.me/{{ settings()->whatsapp() }}?text={{ rawurlencode('Здравствуйте! Заявка '.$order->number) }}"
               target="_blank" rel="noopener"
               class="btn btn-outline">
                Написать в WhatsApp
            </a>
        @endif
    </div>
</div>

@endsection
