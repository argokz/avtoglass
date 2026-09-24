@extends('documents.layout')

@section('title', 'Товарный чек '.$document->number)
@section('doc-title', 'Товарный чек')

@section('content')

<h2>Покупатель</h2>
<div>{{ $order->customer->name }}, тел. {{ $order->customer->phone }}</div>
<div class="small muted">Заявка {{ $order->number }} от {{ $order->created_at->format('d.m.Y') }}</div>

<table>
    <thead>
    <tr>
        <th style="width:26px">№</th>
        <th>Наименование</th>
        <th style="width:70px" class="num">Кол-во</th>
        <th style="width:90px" class="num">Цена, ₸</th>
        <th style="width:100px" class="num">Сумма, ₸</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>
                {{ $item->name }}
                @if ($item->supplier_name)
                    <div class="small muted">производитель: {{ $item->supplier_name }}</div>
                @endif
            </td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ number_format((float) $item->price, 2, ',', ' ') }}</td>
            <td class="num">{{ number_format($item->subtotal(), 2, ',', ' ') }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td></td>
        <td class="right bold" style="width:200px">Итого:</td>
        <td class="right bold" style="width:110px">{{ number_format((float) $order->total_amount, 2, ',', ' ') }} ₸</td>
    </tr>
    @if (! settings()->requisites()['vat_payer'])
        <tr><td colspan="3" class="right small muted">Без НДС</td></tr>
    @endif
</table>

<div class="small" style="margin-top:6px">Всего наименований: {{ $order->items->count() }}</div>

@if ($receipt = $order->receipts->firstWhere('status', App\Enums\ReceiptStatus::Issued))
    <div class="note">
        Фискальный чек № {{ $receipt->check_number }}
        @if ($receipt->ticket_url) · {{ $receipt->ticket_url }} @endif
    </div>
@else
    {{-- Товарный чек фискальный не заменяет: это разные документы, и по закону
         нужен именно фискальный. --}}
    <div class="note warn">
        Товарный чек не заменяет фискальный. Фискальный чек выдаётся отдельно
        через онлайн-ККМ в момент оплаты.
    </div>
@endif

<div class="sign">
    <div>
        <div class="small muted">Отпустил</div>
        <div class="line small">подпись, ФИО</div>
    </div>
    <div>
        <div class="small muted">Получил</div>
        <div class="line small">подпись, ФИО</div>
    </div>
</div>

@endsection
