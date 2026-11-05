@extends('layouts.app')

@section('title', 'Тестовая оплата')

@section('content')

<div class="mx-auto max-w-md py-8">
    {{-- Намеренно не похоже на остальной сайт: это «чужая» страница,
         на месте которой у боевой кассы будет домен банка. --}}
    <div class="rounded-card border-2 border-dashed border-line-strong bg-sunken p-6">
        <div class="flex items-center justify-between gap-3">
            <span class="rounded-pill bg-warn-soft px-3 py-1 text-xs font-bold text-warn-ink">
                Тестовая касса
            </span>
            <span class="font-mono text-xs text-faint">{{ $payment->provider }}</span>
        </div>

        <p class="mt-4 text-sm text-muted">Заказ {{ $payment->order->number }}</p>
        <div class="font-display text-4xl font-extrabold tracking-tight">
            {{ number_format((float) $payment->amount, 2, ',', ' ') }} ₸
        </div>

        <p class="mt-4 text-sm text-muted">
            Настоящих денег здесь не списывается. Выберите, чем закончится операция —
            магазин обработает результат так же, как обработал бы ответ банка.
        </p>

        <div class="mt-6 space-y-2">
            <a href="{{ $links['success'] }}"
               class="block w-full rounded-xl bg-brand py-3.5 text-center font-bold text-on-brand
                      transition hover:bg-brand-hover">
                Оплатить успешно
            </a>

            <a href="{{ $links['fail'] }}"
               class="block w-full rounded-xl border border-line bg-surface py-3 text-center font-semibold
                      transition hover:bg-sunken">
                Банк отклонил
            </a>

            <a href="{{ $links['cancel'] }}"
               class="block w-full py-2 text-center text-sm text-muted transition hover:text-ink">
                Отменить и вернуться
            </a>
        </div>

        <p class="mt-5 text-center text-xs text-faint">
            Ссылка действует {{ config('payments.ttl_minutes') }} минут
        </p>
    </div>
</div>

@endsection
