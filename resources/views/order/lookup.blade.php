@extends('layouts.app')

@section('title', 'Статус заявки — '.settings()->brandName())
@section('description', 'Найдите заявку по телефону и номеру, который назвали при оформлении.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Мои заявки</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <h1 class="text-2xl font-extrabold sm:text-3xl">Найти заявку</h1>
    <p class="mt-2 text-sm text-muted">
        Номер и телефон — те, что оставляли при оформлении. Регистрироваться не нужно.
    </p>

    <form method="post" action="{{ route('orders.find') }}" class="card mt-6 space-y-4 p-5 sm:p-6">
        @csrf

        <div class="hidden" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label for="lookup-phone" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
            <input id="lookup-phone" type="tel" name="phone" maxlength="32" value="{{ old('phone') }}"
                   placeholder="+7 700 000 00 00" required
                   class="input @error('phone') input-error @enderror">
            @error('phone')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="lookup-number" class="mb-1.5 block text-xs font-bold text-muted">Номер заявки</label>
            <input id="lookup-number" type="text" name="number" maxlength="24" value="{{ old('number') }}"
                   placeholder="АС-000123" required
                   class="input @error('number') input-error @enderror">
            @error('number')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
            Показать заявки
        </button>
    </form>

    <p class="mt-4 text-center text-xs text-faint">
        Не помните номер — позвоните, назовём по телефону.
    </p>
</div>

@endsection
