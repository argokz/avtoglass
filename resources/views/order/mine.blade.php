@extends('layouts.app')

@section('title', 'Мои заявки — '.settings()->brandName())

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Мои заявки</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold sm:text-3xl">Мои заявки</h1>
            @if ($customer)
                <p class="mt-1 text-sm text-muted">{{ $customer->name }}</p>
            @endif
        </div>
        <form method="post" action="{{ route('orders.logout') }}">
            @csrf
            <button type="submit" class="rounded-pill border border-line px-4 py-2 text-xs font-semibold
                                         transition hover:bg-sunken">
                Выйти
            </button>
        </form>
    </div>

    @if ($orders->isEmpty())
        <div class="mt-6 rounded-card border border-line bg-surface px-5 py-8 text-center text-sm">
            <p class="font-semibold">Заявок пока нет</p>
            <p class="mt-1 text-muted">Если оформляли недавно — проверьте номер телефона или позвоните.</p>
        </div>
    @else
        <ul class="mt-6 space-y-3">
            @foreach ($orders as $order)
                <li>
                    <a href="{{ route('order.track', $order) }}"
                       class="block rounded-card border border-line bg-surface p-4 transition hover:border-brand/40">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-display font-extrabold">{{ $order->number }}</span>
                            <span class="text-xs font-semibold text-muted">{{ $order->status->label() }}</span>
                        </div>
                        <p class="mt-1 text-sm text-muted">
                            {{ $order->created_at->translatedFormat('j F Y') }}
                            @if ($order->items->isNotEmpty())
                                · {{ $order->items->first()->name }}
                            @endif
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>

@endsection
