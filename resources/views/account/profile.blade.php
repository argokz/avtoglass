@extends('layouts.app')

@section('title', 'Профиль — '.settings()->brandName())

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('account.orders') }}" class="hover:text-ink">Кабинет</a>
    <span class="text-line-strong">/</span>
    <span class="text-ink">Профиль</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-2xl font-extrabold sm:text-3xl">Профиль</h1>
            <p class="mt-1 text-sm text-muted">
                Эти данные подставляются в заявку. Заказывать можно и без кабинета.
            </p>
        </div>
        <a href="{{ route('account.orders') }}" class="btn btn-outline btn-sm shrink-0">К заявкам</a>
    </div>

    @if (session('status'))
        <p class="card mt-4 bg-ok-soft px-4 py-3 text-sm font-bold text-ok-ink">
            {{ session('status') }}
        </p>
    @endif

    <form method="post" action="{{ route('account.profile.update') }}" class="card mt-6 space-y-5 p-5 sm:p-6">
        @csrf

        <section class="space-y-3">
            <h2 class="text-sm font-extrabold">Контакты</h2>

            <div>
                <label for="profile-name" class="mb-1.5 block text-xs font-bold text-muted">Имя</label>
                <input id="profile-name" type="text" name="name" maxlength="120" required
                       value="{{ old('name', $user->name) }}" autocomplete="name"
                       class="input @error('name') input-error @enderror">
                @error('name')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="profile-phone" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
                <input id="profile-phone" type="tel" value="{{ телефонДляГлаз($user->phone) }}" disabled
                       class="input opacity-70">
                <p class="mt-1 text-xs text-faint">Это логин. Сменить номер поможет менеджер.</p>
            </div>

            <div>
                <label for="profile-email" class="mb-1.5 block text-xs font-bold text-muted">
                    Почта <span class="font-normal text-faint">— необязательно</span>
                </label>
                <input id="profile-email" type="email" name="email" maxlength="190"
                       value="{{ old('email', $user->email) }}" autocomplete="email"
                       class="input @error('email') input-error @enderror">
                @error('email')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="space-y-3 border-t border-line pt-5">
            <h2 class="text-sm font-extrabold">Адрес доставки</h2>
            <p class="text-xs text-muted">Подставим в следующую заявку с доставкой или выездом.</p>

            <div>
                <label for="profile-city" class="mb-1.5 block text-xs font-bold text-muted">Город</label>
                <input id="profile-city" type="text" name="default_city" maxlength="64"
                       value="{{ old('default_city', $customer?->default_city ?? currentCity()) }}"
                       class="input">
            </div>

            <div>
                <label for="profile-address" class="mb-1.5 block text-xs font-bold text-muted">Адрес</label>
                <input id="profile-address" type="text" name="default_address" maxlength="255"
                       value="{{ old('default_address', $customer?->default_address) }}"
                       placeholder="Улица, дом, ориентир"
                       class="input @error('default_address') input-error @enderror">
                @error('default_address')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="space-y-3 border-t border-line pt-5">
            <h2 class="text-sm font-extrabold">Автомобиль</h2>
            <p class="text-xs text-muted">Чтобы не набирать VIN и госномер каждый раз.</p>

            <div>
                <label for="profile-vin" class="mb-1.5 block text-xs font-bold text-muted">VIN</label>
                <input id="profile-vin" type="text" name="vin" maxlength="24"
                       value="{{ old('vin', $customer?->vin) }}"
                       placeholder="17 символов"
                       class="input font-mono uppercase">
            </div>

            <div>
                <label for="profile-plate" class="mb-1.5 block text-xs font-bold text-muted">Госномер</label>
                <input id="profile-plate" type="text" name="car_plate" maxlength="16"
                       value="{{ old('car_plate', $customer?->car_plate) }}"
                       placeholder="123 ABC 01"
                       class="input uppercase">
            </div>
        </section>

        <section class="space-y-3 border-t border-line pt-5">
            <h2 class="text-sm font-extrabold">Организация и опт</h2>
            @if ($customer?->seesWholesalePrices())
                <p class="rounded-2xl bg-brand-soft px-3 py-2 text-xs font-semibold text-brand-ink">
                    Оптовые цены открыты
                    @if ($customer->wholesale_discount_percent)
                        · скидка {{ (int) $customer->wholesale_discount_percent }}%
                    @endif
                </p>
            @elseif ($customer?->wholesale_status === \App\Enums\WholesaleStatus::Pending)
                <p class="rounded-2xl bg-warn-soft px-3 py-2 text-xs text-warn-ink">
                    Заявку на опт смотрит менеджер. Пока в каталоге розничные цены.
                </p>
            @else
                <p class="text-xs text-muted">Сервисам и юрлицам менеджер открывает оптовые цены после проверки.</p>
                <label class="flex cursor-pointer items-start gap-2.5 text-sm">
                    <input type="checkbox" name="wholesale" value="1" @checked(old('wholesale'))
                           class="mt-0.5 accent-brand">
                    <span>Отправить заявку на опт</span>
                </label>
            @endif

            <div>
                <label for="profile-company" class="mb-1.5 block text-xs font-bold text-muted">Организация</label>
                <input id="profile-company" type="text" name="company_name" maxlength="190"
                       value="{{ old('company_name', $customer?->company_name) }}"
                       class="input @error('company_name') input-error @enderror">
                @error('company_name')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="profile-bin" class="mb-1.5 block text-xs font-bold text-muted">БИН / ИИН</label>
                <input id="profile-bin" type="text" name="bin" inputmode="numeric" maxlength="12"
                       value="{{ old('bin', $customer?->bin) }}"
                       class="input font-mono @error('bin') input-error @enderror">
                @error('bin')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="space-y-3 border-t border-line pt-5">
            <h2 class="text-sm font-extrabold">Новый пароль</h2>
            <p class="text-xs text-muted">Оставьте пустым, если менять не нужно.</p>

            <div>
                <label for="profile-password" class="mb-1.5 block text-xs font-bold text-muted">Пароль</label>
                <div class="relative">
                    <input id="profile-password" type="password" name="password" autocomplete="new-password"
                           class="input !pr-12 @error('password') input-error @enderror">
                    <button type="button" data-password-toggle="#profile-password"
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

            <div>
                <label for="profile-password2" class="mb-1.5 block text-xs font-bold text-muted">Пароль ещё раз</label>
                <input id="profile-password2" type="password" name="password_confirmation" autocomplete="new-password"
                       class="input">
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
            Сохранить
        </button>
    </form>
</div>

@endsection
