@extends('layouts.app')

@section('title', 'Оплата картой')

@section('content')

<div class="mx-auto max-w-md py-8">
    <div class="rounded-card border border-line bg-surface p-6 text-center shadow-card">
        <p class="text-sm text-muted">Заказ {{ $payment->order->number }}</p>

        <div class="mt-1 font-display text-4xl font-extrabold tracking-tight">
            {{ number_format((float) $payment->amount, 0, ',', ' ') }} ₸
        </div>

        @if (config('payments.halyk.test_mode'))
            <p class="mt-3 rounded-pill bg-warn-soft px-3 py-1.5 text-xs font-semibold text-warn-ink">
                Тестовый терминал банка — деньги не списываются
            </p>
        @endif

        {{-- Форму карты рисует банк, внутри своего фрейма: номер карты
             на нашу страницу не попадает и через наш сервер не проходит. --}}
        <div id="halyk-status" class="mt-6 text-sm text-muted">Открываем форму оплаты…</div>

        <button id="halyk-retry" hidden
                class="mt-4 w-full rounded-xl bg-brand py-3.5 font-bold text-on-brand transition hover:bg-brand-hover">
            Открыть форму оплаты
        </button>

        <a href="{{ route('payment.result', $payment) }}"
           class="mt-3 block text-sm text-muted transition hover:text-ink">
            Вернуться к заказу
        </a>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ $script }}"></script>
<script>
    (function () {
        var payload = @json($payload);
        var status = document.getElementById('halyk-status');
        var retry = document.getElementById('halyk-retry');

        function start() {
            // Виджет грузится с домена банка. Если его заблокировали
            // расширением или сетью, объекта halyk просто не будет —
            // клиенту надо сказать об этом, а не оставлять пустой экран.
            if (typeof halyk === 'undefined' || typeof halyk.pay !== 'function') {
                status.textContent = 'Не удалось загрузить форму оплаты. Проверьте блокировщик рекламы или попробуйте другой браузер.';
                return;
            }

            status.textContent = 'Форма оплаты открыта в отдельном окне.';
            retry.hidden = false;
            halyk.pay(payload);
        }

        retry.addEventListener('click', start);
        window.addEventListener('load', start);
    })();
</script>
@endpush
