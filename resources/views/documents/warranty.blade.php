@extends('documents.layout')

@section('title', 'Гарантийный талон '.$document->number)
@section('doc-title', 'Гарантийный талон')

@section('content')

<h2>Владелец</h2>
<div>{{ $order->customer->name }}, тел. {{ $order->customer->phone }}</div>
<div class="small muted">Заявка {{ $order->number }} от {{ $order->created_at->format('d.m.Y') }}</div>

<h2>Изделие</h2>
<table>
    <thead>
    <tr>
        <th style="width:26px">№</th>
        <th>Наименование</th>
        <th style="width:120px">Производитель</th>
        <th style="width:90px" class="num">Гарантия</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $item->name }}</td>
            <td>{{ $item->supplier_name ?: '—' }}</td>
            <td class="num">{{ $warrantyMonths }} мес.</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="small" style="margin-top:8px">
    Гарантия действует с {{ $document->issued_on->format('d.m.Y') }}
    по {{ $document->issued_on->copy()->addMonths($warrantyMonths)->format('d.m.Y') }}.
</div>

<h2>Что покрывает гарантия</h2>
<div class="small">
    Заводские дефекты стекла: расслоение триплекса, помутнение, искажение
    изображения, дефекты напыления и обогревающих элементов, отслоение молдинга
    заводской установки.
</div>

<h2>Что гарантией не покрывается</h2>
<div class="small">
    Механические повреждения после установки — сколы и трещины от камней, удары,
    перекос кузова после ДТП. Повреждения от нарушения правил эксплуатации в первые
    сутки после вклейки: мойка под давлением, хлопки дверьми при закрытых окнах,
    эксплуатация до полимеризации клея.
    @if ($hasCamera)
        Гарантия не распространяется на работу систем помощи водителю, если после
        замены стекла не выполнялась калибровка камеры.
    @endif
</div>

@if ($hasCamera)
    <div class="note warn">
        На автомобиле установлена камера систем помощи водителю. После замены
        стекла обязательна калибровка — без неё системы работают некорректно.
    </div>
@endif

<div class="note">
    Гарантия действует при предъявлении этого талона и документа об оплате.
    По гарантийным вопросам: @foreach (settings()->phones() as $phone){{ $phone['display'] }}@if (! $loop->last), @endif @endforeach
</div>

<div class="sign">
    <div>
        <div class="small muted">Продавец</div>
        <div class="line small">подпись, печать</div>
    </div>
    <div>
        <div class="small muted">С условиями гарантии ознакомлен</div>
        <div class="line small">подпись покупателя</div>
    </div>
</div>

@endsection
