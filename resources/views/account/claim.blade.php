@extends('layouts.app')

@section('title', 'Открыть историю заявок — '.settings()->brandName())

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('account.orders') }}" class="hover:text-ink">Кабинет</a>
    <span class="text-line-strong">/</span>
    <span class="text-ink">История</span>
@endsection

@section('content')

<div class="mx-auto max-w-lg py-6">
    <h1 class="text-2xl font-extrabold sm:text-3xl">Открыть историю заявок</h1>

    <p class="mt-2 text-sm text-muted">
        На ваш номер у нас уже есть заявки. Назовите номер любой из них — этого достаточно,
        чтобы убедиться, что заказы ваши. Так же мы не покажем их никому другому,
        кто зарегистрируется на этот номер.
    </p>

    <form method="post" action="{{ route('account.claim.store') }}"
          class="card mt-6 space-y-4 p-5 sm:p-6">
        @csrf

        <div>
            <label for="claim-number" class="mb-1.5 block text-xs font-bold text-muted">Номер заявки</label>
            <input id="claim-number" type="text" name="number" maxlength="24" value="{{ old('number') }}"
                   placeholder="АС-000123" required
                   class="input @error('number') input-error @enderror">
            <p class="mt-1 text-xs text-faint">Можно просто цифры — например, 123.</p>
            @error('number')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
            Открыть историю
        </button>
    </form>

    <p class="mt-4 text-center text-xs text-muted">
        Не помните номер? Позвоните нам —
        <a href="tel:{{ settings()->phones()[0]['tel'] ?? '' }}" class="font-bold text-brand-ink">{{ settings()->phones()[0]['display'] ?? '' }}</a>,
        менеджер найдёт заявку и назовёт номер.
    </p>

    <p class="mt-2 text-center text-xs text-faint">
        <a href="{{ route('account.orders') }}" class="underline-offset-4 hover:underline">Вернуться в кабинет</a>
    </p>
</div>

@endsection
