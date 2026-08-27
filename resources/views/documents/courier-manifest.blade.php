@extends('documents.layout')

@section('title', 'Реестр отправлений '.$document->number)
@section('doc-title', 'Реестр отправлений')

@section('content')

<h2>Смена</h2>
<table>
    <tr><th style="width:150px">Исполнитель</th><td>{{ $courier->name }}</td></tr>
    <tr><th>Дата</th><td>{{ $day->format('d.m.Y') }}</td></tr>
    <tr><th>Всего адресов</th><td>{{ $assignments->count() }}</td></tr>
</table>

<h2>Маршрут</h2>
<table>
    <thead>
    <tr>
        <th style="width:26px">№</th>
        <th style="width:52px">Время</th>
        <th style="width:78px">Заявка</th>
        <th>Адрес и клиент</th>
        <th>Что везём</th>
        <th style="width:86px" class="num">К оплате, ₸</th>
        <th style="width:110px">Подпись клиента</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($assignments as $a)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $a->scheduled_at->format('H:i') }}</td>
            <td>{{ $a->order->number }}</td>
            <td>
                {{ $a->address ?: $a->servicePoint?->address ?: '—' }}
                <div class="small muted">{{ $a->order->customer->name }}, {{ $a->order->customer->phone }}</div>
            </td>
            <td class="small">
                @foreach ($a->order->items as $item)
                    {{ $item->name }}@if ($item->quantity > 1) ×{{ $item->quantity }}@endif<br>
                @endforeach
            </td>
            {{-- Курьер должен видеть, где брать деньги, а где заказ уже оплачен --}}
            <td class="num">
                @if ($a->order->isPaid())
                    <span class="small muted">оплачено</span>
                @else
                    {{ number_format((float) $a->order->total_amount, 0, ',', ' ') }}
                @endif
            </td>
            <td></td>
        </tr>
    @endforeach
    </tbody>
</table>

@php
    $toCollect = $assignments->reject(fn ($a) => $a->order->isPaid())
        ->sum(fn ($a) => (float) $a->order->total_amount);
@endphp

<table class="totals">
    <tr>
        <td></td>
        <td class="right bold" style="width:220px">Итого получить наличными:</td>
        <td class="right bold" style="width:110px">{{ number_format($toCollect, 2, ',', ' ') }} ₸</td>
    </tr>
</table>

<div class="note">
    Стекло — хрупкий груз. Перед выездом проверьте комплектность и отсутствие
    сколов; при передаче клиенту подпись в графе означает, что претензий
    к внешнему виду нет.
</div>

<div class="sign">
    <div>
        <div class="small muted">Выдал со склада</div>
        <div class="line small">подпись, ФИО</div>
    </div>
    <div>
        <div class="small muted">Принял к доставке</div>
        <div class="line small">{{ $courier->name }}</div>
    </div>
</div>

@endsection
