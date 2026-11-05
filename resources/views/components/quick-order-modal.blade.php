{{--
    Модалка «Купить в 1 клик». Одна на страницу: кнопки с data-quick-order
    подставляют адрес формы и название товара. После ошибки валидации
    открывается сама — человек не должен искать, что пошло не так.
--}}
@php
    $quickFailed = $errors->hasAny(['phone', 'privacy_consent']) && old('_quick_order');
@endphp

<dialog id="quick-order-modal" aria-labelledby="quick-order-title"
        class="m-auto w-[min(24rem,calc(100vw-2rem))] rounded-card border border-line bg-surface p-0 shadow-lift backdrop:bg-night/60"
        @if ($quickFailed) data-open-on-load data-action="{{ old('_quick_order') }}" @endif>
    <form method="dialog" class="flex items-start justify-between gap-4 p-5 pb-0">
        <div>
            <h2 id="quick-order-title" class="text-lg font-extrabold">Купить в 1 клик</h2>
            <p class="mt-1 text-sm text-muted" data-quick-order-product></p>
        </div>
        <button type="submit" aria-label="Закрыть"
                class="grid h-11 w-11 shrink-0 place-items-center rounded-pill text-muted transition hover:bg-sunken hover:text-ink">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/>
            </svg>
        </button>
    </form>

    <form method="post" data-quick-order-form class="space-y-4 p-5">
        @csrf
        <input type="hidden" name="_quick_order" value="">
        <input type="hidden" name="form_token" value="{{ (string) Illuminate\Support\Str::uuid() }}">
        {{-- Ловушка для ботов: люди это поле не видят и не заполняют --}}
        <div class="absolute -left-[9999px]" aria-hidden="true">
            <label>Не заполняйте <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div>
            <label for="quick-order-phone" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
            <input id="quick-order-phone" type="tel" name="phone" required maxlength="32"
                   value="{{ $quickFailed ? old('phone') : '' }}"
                   autocomplete="tel" inputmode="tel" placeholder="+7 (700) 000-00-00"
                   class="input @if ($quickFailed) input-error @endif">
            @if ($quickFailed)
                <p class="mt-1 text-xs text-warn-ink">{{ $errors->first('phone') }}</p>
            @else
                <p class="mt-1 text-xs text-muted">Перезвоним, подтвердим наличие и оформим заказ</p>
            @endif
        </div>

        @if (settings()->enabled('privacy_consent'))
            <label class="flex cursor-pointer items-start gap-2.5 text-xs leading-relaxed text-muted">
                <input type="checkbox" name="privacy_consent" value="1" required class="mt-0.5 accent-brand">
                <span>Согласен на обработку персональных данных (<a href="{{ route('privacy') }}" class="font-semibold text-brand-ink underline-offset-2 hover:underline">подробнее</a>)</span>
            </label>
        @endif

        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">Жду звонка</button>
    </form>
</dialog>
