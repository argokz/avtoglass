@extends('layouts.app')

@section('title', 'Кабинет — '.settings()->brandName())

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Кабинет</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-extrabold sm:text-3xl">Мои заявки</h1>
            <p class="mt-1 truncate text-sm text-muted">{{ $user->name }} · {{ телефонДляГлаз($user->phone) }}</p>
            @if ($user->customer?->seesWholesalePrices())
                <p class="mt-1 text-xs font-bold text-brand-ink">Оптовые цены</p>
            @elseif ($user->customer?->wholesale_status === \App\Enums\WholesaleStatus::Pending)
                <p class="mt-1 text-xs text-warn-ink">Заявка на опт на проверке</p>
            @endif
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <a href="{{ route('account.profile') }}" class="btn btn-outline btn-sm">Профиль</a>
            <form method="post" action="{{ route('account.logout') }}" data-no-loading>
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">Выйти</button>
            </form>
        </div>
    </div>

    <p class="mt-3 text-xs text-muted">
        Кабинет необязателен: заявку можно оставить и без входа.
        Здесь удобнее смотреть историю и не набирать адрес заново.
    </p>

    @if (session('status'))
        <p class="card mt-4 bg-ok-soft px-4 py-3 text-sm font-bold text-ok-ink">
            {{ session('status') }}
        </p>
    @endif

    @if ($user->customer?->seesWholesalePrices())
        <a href="{{ route('wholesale.index') }}" class="btn btn-primary mt-4 w-full sm:w-auto">
            Новая оптовая заявка
        </a>
    @endif

    @if ($естьЧтоПривязать)
        <div class="card mt-4 border-warn/30 bg-warn-soft px-4 py-4 text-sm">
            <p class="font-bold text-warn-ink">По вашему номеру у нас есть заявки</p>
            <p class="mt-1 text-warn-ink">
                Чтобы открыть историю, назовите номер любой из них — так мы убедимся, что это ваши заказы.
            </p>
            <a href="{{ route('account.claim') }}" class="btn btn-primary btn-sm mt-3">
                Открыть историю
            </a>
        </div>
    @endif

    @if ($orders->isEmpty())
        <div class="card mt-6 px-5 py-10 text-center text-sm">
            <p class="font-extrabold">Заявок пока нет</p>
            <p class="mt-1 text-muted">Новые заказы появятся здесь сами — искать по номеру не придётся.</p>
            <a href="{{ route('catalog.brands') }}" class="btn btn-primary mt-5">
                Подобрать стекло
            </a>
        </div>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($orders as $order)
                <li>
                    <a href="{{ route('order.track', $order) }}"
                       class="card card-hover block p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-display font-extrabold">{{ $order->number }}</span>
                            <span class="flex items-center gap-1.5">
                                @if ($order->source === \App\Enums\OrderSource::Wholesale)
                                    <span class="rounded-pill bg-brand-soft px-1.5 py-0.5 text-[10px] font-extrabold tracking-wide text-brand-ink uppercase">Опт</span>
                                @endif
                                <span class="badge badge-muted">{{ $order->status->label() }}</span>
                            </span>
                        </div>
                        <p class="mt-1.5 text-sm text-muted">
                            {{ $order->created_at->translatedFormat('j F Y') }}
                            @if ($order->items->isNotEmpty())
                                · {{ $order->items->first()->name }}
                            @endif
                        </p>
                        @if ($order->address)
                            <p class="mt-1 truncate text-xs text-faint">{{ $order->address }}</p>
                        @endif
                        <div class="mt-2 flex items-baseline justify-between gap-3">
                            @if ($order->total_amount)
                                <span class="price text-lg">
                                    {{ number_format((float) $order->total_amount, 0, ',', ' ') }} ₸
                                </span>
                            @else
                                <span></span>
                            @endif
                            @if ($paid = $order->paidAmount())
                                <span class="badge badge-ok">
                                    оплачено {{ number_format($paid, 0, ',', ' ') }} ₸
                                </span>
                            @endif
                        </div>
                    </a>
                    @if ($order->source === \App\Enums\OrderSource::Wholesale && $user->customer?->seesWholesalePrices())
                        <div class="mt-1.5 text-right">
                            <a href="{{ route('wholesale.index', ['repeat' => $order->id]) }}"
                               class="text-xs font-bold text-brand-ink underline-offset-4 hover:underline">
                                Повторить заявку
                            </a>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

@endsection
