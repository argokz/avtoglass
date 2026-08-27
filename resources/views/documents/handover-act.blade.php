@extends('documents.layout')

@section('title', 'Акт приёма-передачи '.$document->number)
@section('doc-title', 'Акт приёма-передачи')

@section('content')

<div style="margin-top:10px">
    Настоящим подтверждается передача покупателю нижеперечисленных товаров
    по заявке {{ $order->number }} от {{ $order->created_at->format('d.m.Y') }}.
</div>

<h2>Стороны</h2>
<table>
    <tr>
        <th style="width:120px">Передал</th>
        <td>
            {{ config('company.requisites.legal_name') }}
            @if ($assignment?->assignee)
                <br><span class="small muted">{{ $assignment->assignee->name }}</span>
            @endif
        </td>
    </tr>
    <tr>
        <th>Принял</th>
        <td>{{ $order->customer->name }}, тел. {{ $order->customer->phone }}</td>
    </tr>
    @if ($address)
        <tr><th>Адрес передачи</th><td>{{ $address }}</td></tr>
    @endif
    @if ($assignment)
        <tr><th>Дата и время</th><td>{{ $assignment->scheduled_at->format('d.m.Y H:i') }}</td></tr>
    @endif
</table>

<h2>Передано</h2>
<table>
    <thead>
    <tr>
        <th style="width:26px">№</th>
        <th>Наименование</th>
        <th style="width:70px" class="num">Кол-во</th>
        <th style="width:100px" class="num">Сумма, ₸</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $item->name }}</td>
            <td class="num">{{ $item->quantity }}</td>
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
    <tr>
        <td></td>
        <td class="right small" style="width:200px">Оплата:</td>
        <td class="right small">{{ $order->isPaid() ? 'оплачено' : 'при получении' }}</td>
    </tr>
</table>

{{-- Ради этой строки акт и подписывают: стекло бьётся в дороге, и претензии
     по внешнему виду принимаются только в момент передачи. --}}
<div class="note warn">
    Покупатель обязан осмотреть стекло при получении. Претензии по сколам,
    трещинам и иным внешним повреждениям после подписания акта не принимаются.
</div>

<div class="sign">
    <div>
        <div class="small muted">Передал</div>
        <div class="line small">подпись, ФИО</div>
    </div>
    <div>
        <div class="small muted">Принял, претензий к внешнему виду не имею</div>
        <div class="line small">подпись, ФИО, дата</div>
    </div>
</div>

@endsection
