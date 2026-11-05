@extends('layouts.app')

@section('title', 'Вход в кабинет — '.settings()->brandName())
@section('description', 'Вход в кабинет покупателя по телефону.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Вход</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <p class="eyebrow mb-3">ВАШИ АВТОМОБИЛИ И ЗАЯВКИ</p>
    <h1 class="text-2xl font-extrabold sm:text-3xl">Вход в кабинет</h1>
    <p class="mt-2 text-sm text-muted">
        По тому же телефону, что оставляли в заявке.
        Заказать можно и без входа — кабинет нужен для истории, адреса и оптовых цен сервисам.
    </p>

    <form method="post" action="{{ route('account.login.store') }}"
          class="card mt-6 space-y-4 p-5 sm:p-6">
        @csrf

        <div class="hidden" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label for="login-phone" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
            <input id="login-phone" type="tel" name="phone" maxlength="32" value="{{ old('phone') }}"
                   placeholder="+7 700 000 00 00" autocomplete="tel" required
                   class="input @error('phone') input-error @enderror">
            @error('phone')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="login-password" class="mb-1.5 block text-xs font-bold text-muted">Пароль</label>
            <div class="relative">
                <input id="login-password" type="password" name="password" autocomplete="current-password" required
                       class="input !pr-12 @error('password') input-error @enderror">
                <button type="button" data-password-toggle="#login-password"
                        aria-label="Показать пароль" aria-pressed="false"
                        class="absolute top-1/2 right-3 -translate-y-1/2 text-faint transition hover:text-ink">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-7 9.5-7 9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7Z"/>
                        <circle cx="12" cy="12" r="2.5"/>
                    </svg>
                </button>
            </div>
            @error('password')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
            Войти
        </button>

        <p class="text-center text-xs text-faint">
            Ещё нет кабинета?
            <a href="{{ route('account.register') }}" class="font-bold text-brand-ink underline-offset-4 hover:underline">Создать</a>
        </p>
    </form>

    <p class="mt-4 text-center text-xs text-muted">
        Забыли пароль или заказывали без кабинета? Заявку видно
        <a href="{{ route('orders.lookup') }}" class="font-bold text-brand-ink underline-offset-4 hover:underline">по телефону и номеру</a>,
        а доступ восстановит менеджер по телефону
        <a href="tel:{{ settings()->phones()[0]['tel'] ?? '' }}" class="font-bold text-brand-ink">{{ settings()->phones()[0]['display'] ?? '' }}</a>.
    </p>
</div>

@endsection
