@extends('documents.layout')

@section('title', 'Счёт на оплату '.$document->number)
@section('doc-title', 'Счёт на оплату')

@section('content')

@php($requisites = settings()->requisites())
<h2>Поставщик</h2>
<table>
    <tr><th style="width:150px">Наименование</th><td>{{ $requisites['legal_name'] }}</td></tr>
    <tr><th>БИН</th><td>{{ $requisites['bin'] ?: '—' }}</td></tr>
    <tr><th>ИИК</th><td>{{ $requisites['iik'] ?: '—' }}</td></tr>
    <tr><th>Банк</th><td>{{ $requisites['bank'] ?: '—' }}@if ($requisites['bik']), БИК {{ $requisites['bik'] }}@endif</td></tr>
    <tr><th>Адрес</th><td>{{ settings()->address() }}</td></tr>
</table>

<h2>Покупатель</h2>
<table>
    <tr><th style="width:150px">Наименование</th><td>{{ $order->customer->company_name ?: $order->customer->name }}</td></tr>
    @if ($order->customer->company_name)
        <tr><th>Контактное лицо</th><td>{{ $order->customer->name }}</td></tr>
    @endif
    @if ($order->customer->bin)
        <tr><th>БИН/ИИН</th><td>{{ $order->customer->bin }}</td></tr>
    @endif
    <tr><th>Телефон</th><td>{{ $order->customer->phone }}</td></tr>
</table>

<h2>Предмет счёта</h2>
<table>
    <thead>
    <tr>
        <th style="width:26px">№</th>
        <th>Наименование</th>
        <th style="width:60px" class="num">Кол-во</th>
        <th style="width:90px" class="num">Цена, ₸</th>
        <th style="width:100px" class="num">Сумма, ₸</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $item->name }}</td>
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
        <td class="right bold" style="width:200px">Итого к оплате:</td>
        <td class="right bold" style="width:110px">{{ number_format((float) $order->total_amount, 2, ',', ' ') }} ₸</td>
    </tr>
    <tr>
        <td></td>
        <td class="right small" style="width:200px">{{ $requisites['vat_payer'] ? 'В том числе НДС:' : '' }}</td>
        <td class="right small">{{ $requisites['vat_payer'] ? number_format((float) $order->total_amount * 12 / 112, 2, ',', ' ').' ₸' : 'Без НДС' }}</td>
    </tr>
</table>

<div class="small" style="margin-top:8px">
    Всего наименований {{ $order->items->count() }}, на сумму
    {{ number_format((float) $order->total_amount, 2, ',', ' ') }} ₸.
</div>

<div class="note">
    Счёт действителен для оплаты в течение 3 банковских дней. Стекло резервируется
    после поступления денег: цены поставщиков пересматриваются еженедельно.
    В назначении платежа укажите номер счёта {{ $document->number }}.
</div>

<div class="sign">
    <div>
        <div class="small muted">Руководитель</div>
        <div class="line small">{{ $requisites['director'] ?: 'подпись, ФИО' }}</div>
    </div>
    <div>
        <div class="small muted">Главный бухгалтер</div>
        <div class="line small">подпись, ФИО</div>
    </div>
</div>

@endsection
