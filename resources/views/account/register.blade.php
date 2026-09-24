@extends('layouts.app')

@section('title', 'Регистрация — '.settings()->brandName())
@section('description', 'Кабинет покупателя: заявки, статусы и история заказов в одном месте.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Регистрация</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <h1 class="text-2xl font-extrabold sm:text-3xl">Кабинет покупателя</h1>

    <p class="mt-2 text-sm text-muted">
        Кабинет необязателен: заявку можно оставить и без него.
        Он нужен, чтобы видеть историю заказов, помнить адрес доставки и не набирать данные заново —
        <a href="{{ route('catalog.brands') }}" class="font-bold text-brand-ink underline-offset-4 hover:underline">просто выберите стекло</a>.
    </p>

    <form method="post" action="{{ route('account.register.store') }}"
          class="card mt-6 space-y-4 p-5 sm:p-6">
        @csrf

        <div class="hidden" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label for="reg-name" class="mb-1.5 block text-xs font-bold text-muted">Как вас зовут</label>
            <input id="reg-name" type="text" name="name" maxlength="120" value="{{ old('name', $prefillName ?? '') }}"
                   autocomplete="name" required
                   class="input @error('name') input-error @enderror">
            @error('name')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="reg-phone" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
            <input id="reg-phone" type="tel" name="phone" maxlength="32" value="{{ old('phone', $prefillPhone ?? '') }}"
                   placeholder="+7 700 000 00 00" autocomplete="tel" required
                   class="input @error('phone') input-error @enderror">
            <p class="mt-1 text-xs text-faint">
                @if (! empty($prefillPhone) && ! old('phone'))
                    Подставили из последней заявки — можно поправить.
                @else
                    По нему вы будете входить — и по нему же мы перезваниваем.
                @endif
            </p>
            @error('phone')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="reg-email" class="mb-1.5 block text-xs font-bold text-muted">
                Почта <span class="font-normal text-faint">— необязательно</span>
            </label>
            <input id="reg-email" type="email" name="email" maxlength="190" value="{{ old('email') }}"
                   autocomplete="email"
                   class="input @error('email') input-error @enderror">
            @error('email')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="reg-password" class="mb-1.5 block text-xs font-bold text-muted">Пароль</label>
            <div class="relative">
                <input id="reg-password" type="password" name="password" autocomplete="new-password" required
                       class="input !pr-12 @error('password') input-error @enderror">
                <button type="button" data-password-toggle="#reg-password"
                        aria-label="Показать пароль" aria-pressed="false"
                        class="absolute top-1/2 right-3 -translate-y-1/2 text-faint transition hover:text-ink">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12s3.5-7 9.5-7 9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7Z"/>
                        <circle cx="12" cy="12" r="2.5"/>
                    </svg>
                </button>
            </div>
            <p class="mt-1 text-xs text-faint">От восьми символов.</p>
            @error('password')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="reg-password2" class="mb-1.5 block text-xs font-bold text-muted">Пароль ещё раз</label>
            <input id="reg-password2" type="password" name="password_confirmation" autocomplete="new-password" required
                   class="input">
        </div>

        <details class="rounded-2xl border border-line px-4 py-3" @if (old('wholesale')) open @endif>
            <summary class="cursor-pointer text-sm font-bold">Закупаю для сервиса или юрлица</summary>
            <p class="mt-2 text-xs text-muted">
                Оптовые цены откроет менеджер. До этого кабинет работает как обычный.
            </p>
            <label class="mt-3 flex cursor-pointer items-start gap-2.5 text-sm">
                <input type="checkbox" name="wholesale" value="1" @checked(old('wholesale'))
                       class="mt-0.5 accent-brand">
                <span>Отправить заявку на опт</span>
            </label>
            <div class="mt-3 space-y-3">
                <div>
                    <label for="reg-company" class="mb-1.5 block text-xs font-bold text-muted">Организация</label>
                    <input id="reg-company" type="text" name="company_name" maxlength="190"
                           value="{{ old('company_name') }}"
                           class="input @error('company_name') input-error @enderror"
                           placeholder="Название сервиса или ТОО">
                    @error('company_name')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="reg-bin" class="mb-1.5 block text-xs font-bold text-muted">БИН / ИИН</label>
                    <input id="reg-bin" type="text" name="bin" inputmode="numeric" maxlength="12"
                           value="{{ old('bin') }}"
                           class="input font-mono @error('bin') input-error @enderror"
                           placeholder="12 цифр">
                    @error('bin')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
                </div>
            </div>
        </details>

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
            Создать кабинет
        </button>

        <p class="text-center text-xs text-faint">
            Уже есть кабинет?
            <a href="{{ route('account.login') }}" class="font-bold text-brand-ink underline-offset-4 hover:underline">Войти</a>
        </p>
    </form>

    <p class="mt-4 text-center text-xs text-muted">
        Заказывали без кабинета? Заявку можно найти
        <a href="{{ route('orders.lookup') }}" class="font-bold text-brand-ink underline-offset-4 hover:underline">по телефону и номеру</a>.
    </p>
</div>

@endsection
