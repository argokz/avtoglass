@extends('layouts.app')

@section('title', 'Заявка принята')

@section('content')

<div class="mx-auto max-w-lg py-10 text-center">
    <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-brand-soft text-brand-ink">
        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/>
        </svg>
    </span>

    <h1 class="mt-5 text-2xl font-bold sm:text-3xl">Заявка принята</h1>

    @if ($number)
        <p class="mt-2 text-muted">
            Номер заявки — <span class="font-display font-extrabold text-ink">{{ $number }}</span>.
            Назовите его, если будете звонить сами.
        </p>
    @else
        <p class="mt-2 text-muted">Менеджер свяжется с вами в ближайшее время.</p>
    @endif

    @if (session('payment_error'))
        <div class="mt-5 rounded-card border border-warn/40 bg-warn-soft p-4 text-sm text-warn-ink">
            {{ session('payment_error') }}
        </div>
    @endif

    @if ($payable)
        <div class="mt-6 rounded-card border border-brand/30 bg-brand-soft p-5">
            <div class="font-display text-2xl font-extrabold">
                {{ number_format((float) $amount, 0, ',', ' ') }} ₸
            </div>
            <p class="mt-1 text-sm text-brand-ink">
                Оплатите онлайн — стекло сразу зарезервируем за вами
            </p>

            <form method="post" action="{{ route('payment.start') }}" class="mt-4">
                @csrf
                <button class="w-full rounded-xl bg-brand py-3.5 font-bold text-on-brand
                               transition hover:bg-brand-hover active:scale-[0.99]">
                    Оплатить картой
                </button>
            </form>

            <p class="mt-2 text-center text-xs text-brand-ink/70">
                Работу можно оплатить отдельно при получении
            </p>
        </div>
    @endif

    <div class="mt-6 rounded-card border border-line bg-surface p-5 text-left">
        <h2 class="font-bold">Что дальше</h2>
        <ol class="mt-3 space-y-2.5 text-sm text-muted">
            <li class="flex gap-3">
                <span class="font-display font-extrabold text-brand">1</span>
                Менеджер проверит наличие стекла у поставщиков и перезвонит.
            </li>
            <li class="flex gap-3">
                <span class="font-display font-extrabold text-brand">2</span>
                Назовёт итоговую цену — со стеклом, работой и, если нужно, калибровкой камеры.
            </li>
            <li class="flex gap-3">
                <span class="font-display font-extrabold text-brand">3</span>
                Договоритесь о времени замены или доставки.
            </li>
        </ol>
    </div>

    <div class="mt-6 flex flex-wrap justify-center gap-3">
        <a href="{{ route('catalog.brands') }}"
           class="rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-hover">
            Вернуться в каталог
        </a>
        <a href="tel:{{ config('company.phones.0.tel') }}"
           class="rounded-pill border border-line px-6 py-2.5 text-sm font-semibold transition hover:bg-sunken">
            Позвонить
        </a>
    </div>
</div>

@endsection
