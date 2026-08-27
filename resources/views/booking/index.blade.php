@extends('layouts.app')

@section('title', $product ? "Запись на замену: {$product->name}" : 'Запись на замену автостекла')
@section('description', 'Выберите сервис, день и время — запишитесь на замену стекла онлайн, без звонка.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Запись на замену</span>
@endsection

@section('content')

<div class="mx-auto max-w-2xl">
    <h1 class="text-2xl font-bold sm:text-3xl">Запись на замену</h1>
    <p class="mt-1.5 text-sm text-muted">
        Выберите время сами — перезванивать и согласовывать не придётся.
    </p>
</div>

<div class="mt-8">
    <livewire:slot-picker :product="$product" />
</div>

@endsection
