@extends('layouts.app')

@section('title', 'Подбор автостекла по VIN')
@section('description', 'Введите VIN — определим марку, год выпуска и страну сборки, подберём стекло на вашу машину.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Подбор по VIN</span>
@endsection

@section('content')

<div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-bold sm:text-3xl">Подбор по VIN</h1>
    <p class="mt-1.5 text-sm text-muted">
        Стекло на Camry 2006 и Camry 2018 разное, а год по документам помнят не все.
        VIN снимает этот вопрос.
    </p>
</div>

<div class="mt-8">
    <livewire:vin-lookup />
</div>

@endsection
