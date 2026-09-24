@props([
    'quotes',
    'fulfillment',
    'submit' => false,
    'form' => null,
])

@php
    $quote = $quotes[$fulfillment] ?? $quotes['installation'] ?? ['glass' => 0, 'works' => 0, 'visit' => 0, 'total' => 0, 'hasItemsWithoutPrice' => false];
    $fmt = fn (float $n) => number_format($n, 0, ',', ' ').' ₸';
@endphp

<div class="card p-5 shadow-lift" data-cart-summary>
    <h3 class="text-sm font-extrabold">{{ $submit ? 'Ваш заказ' : 'Итого' }}</h3>

    <dl class="mt-3 space-y-2 text-sm">
        <div class="flex items-baseline justify-between gap-3">
            <dt class="text-muted">Стекло</dt>
            <dd class="font-semibold" data-quote-glass>{{ $fmt($quote['glass']) }}</dd>
        </div>
        <div data-quote-works-row @class(['flex items-baseline justify-between gap-3', 'hidden' => $quote['works'] <= 0])>
            <dt class="text-muted">Установка</dt>
            <dd class="font-semibold" data-quote-works>{{ $fmt($quote['works']) }}</dd>
        </div>
        <div data-quote-visit-row @class(['flex items-baseline justify-between gap-3', 'hidden' => $quote['visit'] <= 0])>
            <dt class="text-muted">Выезд</dt>
            <dd class="font-semibold" data-quote-visit>{{ $fmt($quote['visit']) }}</dd>
        </div>
    </dl>

    @if ($quote['hasItemsWithoutPrice'] ?? false)
        <p class="mt-3 rounded-2xl bg-warn-soft p-3 text-xs text-warn-ink">
            По некоторым позициям цена уточняется — итог назовёт менеджер.
        </p>
    @endif

    <div class="mt-4 flex items-baseline justify-between border-t border-line pt-4">
        <span class="text-sm font-semibold text-muted">Итого</span>
        <span class="price price-lg" data-quote-total>{{ $fmt($quote['total']) }}</span>
    </div>

    @if ($submit)
        <button type="submit" @if ($form) form="{{ $form }}" @endif
                class="btn btn-primary btn-lg mt-4 w-full !rounded-2xl whitespace-normal">
            Оформить заказ
        </button>
        <p class="mt-2 text-center text-xs leading-relaxed text-muted">
            Менеджер перезвонит, подтвердит наличие и назовёт срок
        </p>
    @else
        <a href="#oformlenie" class="btn btn-primary mt-4 w-full !rounded-xl">
            Перейти к оформлению
        </a>
        @guest
            <p class="mt-3 text-center text-xs text-muted">
                <a href="{{ route('account.login') }}" class="font-semibold text-brand-ink underline-offset-2 hover:underline">Войдите</a>,
                чтобы не заполнять поля снова
            </p>
        @endguest
    @endif
</div>
