@extends('layouts.app')

@section('title', 'Оплата картой')

@section('content')

<div class="mx-auto max-w-md py-8">
    <div class="rounded-card border border-line bg-surface p-6 text-center shadow-card">
        <p class="text-sm text-muted">Заказ {{ $payment->order->number }}</p>

        <div class="mt-1 font-display text-4xl font-extrabold tracking-tight">
            {{ number_format((float) $payment->amount, 0, ',', ' ') }} ₸
        </div>

        <p class="mt-2 text-sm text-muted">Halyk Pay — заполните карту в окне банка</p>

        @if (config('payments.halyk.test_mode'))
            <div class="mt-4 rounded-2xl bg-warn-soft p-3.5 text-left text-xs leading-relaxed text-warn-ink">
                <p class="font-bold">Тестовый терминал — деньги не списываются</p>
                <p class="mt-2 font-mono text-[13px] text-ink">
                    4405 6397 0401 5096<br>
                    срок 01/27 · CVC 321
                </p>
                <p class="mt-2 text-muted">Карта из песочницы Halyk. 3-D Secure банк спросит сам.</p>
            </div>
        @endif

        {{-- Форму карты рисует банк, внутри своего фрейма: номер карты
             на нашу страницу не попадает и через наш сервер не проходит. --}}
        <div id="halyk-status" class="mt-6 text-sm text-muted">Открываем форму оплаты…</div>

        <button id="halyk-retry" hidden
                class="btn btn-primary mt-4 w-full !rounded-2xl">
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
    <script>
        (function () {
            var payload = @json($payload);
            var scriptSrc = @json($script);
            var status = document.getElementById('halyk-status');
            var retry = document.getElementById('halyk-retry');

            function fail() {
                status.textContent = 'Не удалось загрузить форму оплаты. Проверьте блокировщик рекламы или попробуйте другой браузер.';
                retry.hidden = false;
            }

            function start() {
                if (typeof halyk === 'undefined' || typeof halyk.pay !== 'function') {
                    fail();
                    return;
                }

                status.textContent = 'Переходим на страницу банка…';
                retry.hidden = false;
                halyk.pay(payload);
            }

            function loadScript() {
                if (typeof halyk !== 'undefined' && typeof halyk.pay === 'function') {
                    start();
                    return;
                }

                status.textContent = 'Открываем форму оплаты…';
                retry.hidden = true;

                var previous = document.getElementById('halyk-widget-src');
                if (previous) {
                    previous.remove();
                }

                var script = document.createElement('script');
                script.id = 'halyk-widget-src';
                script.src = scriptSrc;
                script.onload = start;
                script.onerror = fail;
                document.head.appendChild(script);
            }

            retry.addEventListener('click', loadScript);
            loadScript();
        })();
    </script>
@endpush
