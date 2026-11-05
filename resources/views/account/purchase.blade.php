@extends('layouts.app')
@section('title', $order->number.' — '.settings()->brandName())
@section('content')
@php($kk = app()->getLocale() === 'kk')
<div class="account-workspace mx-auto max-w-3xl space-y-6 py-6">
    <div><h1 class="text-2xl font-extrabold">{{ $kk ? 'Тапсырыс' : 'Заказ' }} {{ $order->number }}</h1><p class="mt-2 text-muted">{{ $summary['status_label'] }}</p></div>
    @if (session('status'))<p class="rounded-xl bg-sunken p-4" role="status">{{ session('status') }}</p>@endif
    @if ($order->purchase_state === 'paid_review')<p class="border-l-4 border-brand-ink pl-4 font-bold">{{ $kk ? 'Төлем алынды, орындалуын нақтылаймыз.' : 'Оплата получена, уточняем исполнение.' }}</p>@endif
    @if ($order->purchase_state === 'review_required')<p>{{ $kk ? 'Менеджер ұсынысты дайындауда. Төлем шарттарды қабылдағаннан кейін қолжетімді болады.' : 'Менеджер проверяет условия. Оплата станет доступна после принятия предложения.' }}</p>@endif
    @if ($order->purchase_state === 'reservation_expired')<p>{{ $kk ? 'Резерв мерзімі аяқталды. Шарттарды қайта тексеру үшін менеджерге сұрау жіберіңіз.' : 'Срок резерва истёк. Отправьте запрос менеджеру для повторной проверки условий.' }}</p>@endif
    <div class="divide-y divide-line border-y border-line">
        @foreach ($order->items as $item)<div class="flex justify-between gap-4 py-3"><div class="min-w-0"><p class="font-bold">{{ $item->name }}</p><p class="text-sm text-muted">{{ $item->supplier_name }} · {{ $item->quantity }} {{ $kk ? 'дана' : 'шт.' }}</p></div><span class="shrink-0">{{ number_format($item->subtotal(), 2, ',', ' ') }} ₸</span></div>@endforeach
        @if ($order->fulfillment?->value === 'delivery')
            @php($deliveryMinor = $order->purchase_snapshot['delivery_total']['amount_minor'] ?? null)
            <div class="flex justify-between gap-4 py-3"><span class="font-bold">{{ $kk ? 'Жеткізу' : 'Доставка' }}</span><span class="shrink-0">{{ $deliveryMinor === null ? ($kk ? 'Менеджер нақтылайды' : 'Уточнит менеджер') : number_format($deliveryMinor / 100, 2, ',', ' ').' ₸' }}</span></div>
        @endif
    </div>
    <p class="text-xl font-extrabold">{{ $kk ? 'Барлығы:' : 'Итого:' }} {{ $order->total_amount === null ? ($kk ? 'Менеджер нақтылайды' : 'Уточнит менеджер') : number_format((float) $order->total_amount, 2, ',', ' ').' ₸' }}</p>
    @php($receiving = $order->purchase_snapshot['fulfillment'] ?? [])
    @php($point = null)
    <section class="space-y-2 border-t border-line pt-4">
        <h2 class="font-bold">{{ $kk ? 'Алу және төлем' : 'Получение и оплата' }}</h2>
        <p>{{ $kk ? match($order->fulfillment?->value) {'pickup' => 'Өзі алып кету', 'delivery' => 'Әйнекті жеткізу', 'installation' => 'Сервисте орнату', default => 'Менеджер нақтылайды'} : $order->fulfillment?->label() }} · {{ $order->city }} {{ $order->address }}</p>
        @if (!empty($receiving['service_point_id']))
            @php($point = \App\Models\ServicePoint::query()->find($receiving['service_point_id']))
            <p>{{ $point?->name }} {{ $point?->address }}</p>
        @endif
        @if (!empty($receiving['slot_start']))<p>{{ \Carbon\CarbonImmutable::parse($receiving['slot_start'])->setTimezone($point?->timezone ?? config('app.timezone'))->format('d.m.Y H:i') }}</p>@endif
        <p>{{ $kk ? match($order->payment_method?->value) {'cash' => 'Алған кезде төлеу', 'card' => 'Онлайн картамен', 'kaspi' => 'Kaspi Pay', 'invoice' => 'Ұйымға шот', default => 'Менеджер нақтылайды'} : $order->payment_method?->label() }}</p>
    </section>
    @if ($proposal)
        <section class="space-y-4 rounded-xl border border-line p-4">
            <h2 class="text-lg font-bold">{{ $kk ? 'Менеджер ұсынысы' : 'Предложение менеджера' }} №{{ $proposal->version }}</h2>
            <p class="text-sm text-muted">{{ $kk ? 'Бастапқы өтініммен салыстырып, өзгерістерді тексеріңіз.' : 'Сравните с исходной заявкой выше и проверьте изменения.' }}</p>
            <dl class="divide-y divide-line">
                @foreach (\App\Domain\Ordering\Data\ProposalComparison::rows($order->purchase_snapshot ?? [], $proposal->snapshot, app()->getLocale()) as $row)
                    <div class="space-y-2 py-3">
                        <dt class="font-bold">{{ $row['label'] }} @if ($row['changed'])<span class="ml-2 text-sm text-brand-ink">{{ $kk ? 'Өзгертілді' : 'Изменено' }}</span>@endif</dt>
                        <dd class="grid gap-3 sm:grid-cols-2">
                            <div class="min-w-0"><p class="text-xs text-muted">{{ $kk ? 'Бастапқы өтінім' : 'Исходная заявка' }}</p><p class="whitespace-pre-line break-words text-sm">{{ $row['before'] }}</p></div>
                            <div class="min-w-0"><p class="text-xs text-muted">{{ $kk ? 'Менеджер ұсынысы' : 'Предложение менеджера' }}</p><p class="whitespace-pre-line break-words text-sm">{{ $row['after'] }}</p></div>
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="text-sm text-muted">{{ $kk ? 'Дейін жарамды:' : 'Действует до:' }} {{ $proposal->expires_at->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</p>
            @if ($proposal->expires_at->isFuture())<form method="POST" action="{{ route('purchase.accept', $order->public_token) }}" class="space-y-3">@csrf<input type="hidden" name="proposal_id" value="{{ $proposal->id }}"><label class="flex gap-2"><input type="checkbox" name="consent" required value="1"><span>{{ $kk ? 'Шарттарды және жеке деректерді өңдеуді растаймын.' : 'Подтверждаю условия и обработку персональных данных.' }}</span></label><button class="btn btn-primary">{{ $kk ? 'Ұсынысты қабылдау' : 'Принять предложение' }}</button></form>@endif
        </section>
    @endif
    <div class="flex flex-wrap gap-3">
        @if ($summary['can_pay'] && $order->payment_method?->driver())<form method="POST" action="{{ route('purchase.pay', $order->public_token) }}">@csrf<button class="btn btn-primary">{{ $kk ? 'Төлеу' : 'Оплатить' }}</button></form>@endif
        @if ($order->payment_method?->value === 'invoice' && in_array($order->purchase_state, ['confirmed', 'paid'], true))<a class="btn" href="{{ route('purchase.document', [$order->public_token, 'invoice']) }}">{{ $kk ? 'Ұйымға шот' : 'Счёт на организацию' }}</a>@endif
        @if ($order->purchase_state === 'paid')<p class="py-3 font-bold">{{ $kk ? 'Төленді' : 'Оплачено' }}</p>@endif
        @if (in_array($order->purchase_state, ['confirmed', 'paid'], true))<a class="btn" href="{{ route('purchase.document', [$order->public_token, 'sales_receipt']) }}">{{ $kk ? 'Тауар құжаты' : 'Товарный документ' }}</a>@endif
        <form method="POST" action="{{ route('purchase.repeat', $order->public_token) }}">@csrf<input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="btn">{{ $kk ? 'Тапсырысты қайталау' : 'Повторить заказ' }}</button></form>
    </div>
    <form method="POST" action="{{ route('purchase.change', $order->public_token) }}" class="space-y-3 border-t border-line pt-5">@csrf<label class="block font-bold">{{ $kk ? 'Өзгерту сұрауы' : 'Запросить изменение' }}<textarea class="input mt-2" name="description" required maxlength="1000" rows="3"></textarea></label><p class="text-sm text-muted">{{ $kk ? 'Менеджер тексергенше ағымдағы шарттар сақталады.' : 'Действующие условия сохраняются до согласования изменения менеджером.' }}</p><button class="btn">{{ $kk ? 'Менеджерге жіберу' : 'Отправить менеджеру' }}</button></form>
</div>
@endsection
