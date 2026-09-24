@php $balance = $this->settlement(); @endphp
<section class="rounded-card border border-line bg-surface p-5" data-order-settlement>
    <h2 class="font-extrabold">{{ __('shop.payment') }}</h2><p class="mt-3 font-semibold">{{ __('shop.payment_'.($demoOrder['payment'] ?? 'unpaid')) }}</p>
    <dl class="mt-4 space-y-3 text-sm">
        @foreach (['paid' => 'history_paid', 'refunded' => 'settlement_refunded', 'net' => 'settlement_net', 'due' => 'settlement_due', 'refund' => 'settlement_refund_due'] as $key => $label)<div class="flex flex-wrap justify-between gap-2" data-settlement-{{ $key }}><dt>{{ __('shop.'.$label) }}</dt><dd><x-shop.price :amount="$balance[$key]" /></dd></div>@endforeach
    </dl>
    <p class="mt-4 text-sm text-muted">{{ __('shop.settlement_explanation') }}</p>
    @foreach ($demoOrder['refunds'] ?? [] as $refund)
        <div class="mt-4 rounded-xl border border-line p-3" data-refund="{{ $refund['id'] }}"><p class="font-bold">{{ __('shop.settlement_refund_'.$refund['status']) }}</p><p class="mt-2"><x-shop.price :amount="$refund['amount']" /></p>@if ($refund['status'] !== 'succeeded')<p class="mt-2 text-sm text-muted">{{ __('shop.settlement_refund_note') }}</p>@endif</div>
    @endforeach
    @foreach ($demoOrder['paymentAttempts'] ?? [] as $attempt)<p class="mt-4 text-sm" data-payment-attempt="{{ $attempt['id'] }}">{{ __('shop.settlement_attempt_'.$attempt['status']) }} · <x-shop.price :amount="$attempt['amount']" /></p>@endforeach
    <p class="mt-4 text-sm text-muted">{{ __('shop.history_payment_demo') }}</p>
    <h3 class="mt-5 font-bold">{{ __('shop.history_documents') }}</h3><p class="mt-2 text-sm text-muted">{{ __('shop.history_no_documents') }}</p>
    <details wire:key="settlement-demo-{{ $demoOrder['number'] }}" wire:ignore.self class="mt-5 rounded-xl border border-dashed border-line-strong p-3"><summary class="min-h-11 cursor-pointer py-2 text-sm font-bold">{{ __('shop.settlement_demo') }}</summary><div class="mt-3 flex flex-col items-start gap-3">
        @if (in_array($demoOrder['status'], ['confirmed', 'scheduled'], true) && ($demoOrder['paid'] ?? 0) === 0 && ($demoOrder['payment'] ?? 'unpaid') === 'unpaid' && $demoOrder['total'] !== null && ! $proposal)<button type="button" wire:click="simulatePaidOrder" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_paid') }}</button>@endif
        @if (($proposal['status'] ?? null) === 'accepted' && $balance['due'] > 0 && ($demoOrder['payment'] ?? '') !== 'pending')<button type="button" wire:click="startDemoPayment({{ $demoOrder['version'] }})" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_pay') }}</button>@endif
        @foreach ($demoOrder['paymentAttempts'] ?? [] as $attempt)
            @if ($attempt['status'] === 'pending')<button type="button" wire:click="resolveDemoPayment('{{ $attempt['id'] }}', 'succeeded')" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_payment_success') }}</button><button type="button" wire:click="resolveDemoPayment('{{ $attempt['id'] }}', 'failed')" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_payment_failure') }}</button>@endif
        @endforeach
        @foreach ($demoOrder['refunds'] ?? [] as $refund)
            @if (in_array($refund['status'], ['requested', 'failed'], true))<button type="button" wire:click="startDemoRefund('{{ $refund['id'] }}')" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_refund_start') }}</button>@endif
            @if ($refund['status'] === 'pending')<button type="button" wire:click="resolveDemoRefund('{{ $refund['id'] }}', 'succeeded')" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_refund_success') }}</button><button type="button" wire:click="resolveDemoRefund('{{ $refund['id'] }}', 'failed')" class="btn btn-outline min-h-11">{{ __('shop.settlement_demo_refund_failure') }}</button>@endif
        @endforeach
    </div></details>
</section>
