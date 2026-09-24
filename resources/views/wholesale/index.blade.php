@extends('layouts.app')

@section('title', 'Оптовая заявка — '.settings()->brandName())
@section('description', 'Оптовая заявка для сервисов и юрлиц: список позиций с количеством, одной отправкой.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Опт</span>
@endsection

@section('content')

@if ($customer?->seesWholesalePrices())
    <livewire:wholesale-table />
@elseif ($customer?->wholesale_status === \App\Enums\WholesaleStatus::Pending)
    <div class="card mx-auto max-w-lg px-6 py-12 text-center">
        <p class="font-display text-lg font-extrabold">Заявка на опт на проверке</p>
        <p class="mx-auto mt-2 max-w-md text-sm text-muted">
            Менеджер смотрит документы. Как только опт откроют, здесь появится
            таблица с оптовыми ценами — а пока цены в каталоге розничные.
        </p>
        <a href="{{ route('account.orders') }}" class="btn btn-outline mt-6">В кабинет</a>
    </div>
@else
    <div class="card mx-auto max-w-lg px-6 py-12 text-center">
        <p class="font-display text-lg font-extrabold">Оптовый раздел</p>
        <p class="mx-auto mt-2 max-w-md text-sm text-muted">
            Здесь сервисы и юрлица собирают заявку списком: галочки, количество, одна отправка.
            Доступ открывает менеджер после заявки на опт.
        </p>
        <a href="{{ route('account.profile') }}" class="btn btn-primary mt-6">Подать заявку на опт</a>
    </div>
@endif

@endsection
