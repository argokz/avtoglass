@extends('layouts.app')

@section('title', "Стекло {$brand->name} {$model->name} — цены и подбор")
@section('description', "Лобовые, задние и боковые стёкла {$brand->name} {$model->name}. Сравнение предложений поставщиков.")

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.brands') }}" class="transition hover:text-ink">Каталог</a>
    <span class="text-line-strong">/</span>
    <a href="{{ route('catalog.models', $brand) }}" class="transition hover:text-ink">{{ $brand->name }}</a>
    <span class="text-line-strong">/</span><span class="text-ink">{{ $model->name }}</span>
@endsection

@section('content')

{{--
    Вся страница — один Livewire-компонент: фильтры и выдача меняются вместе,
    и разделять их между шаблоном и компонентом значило бы держать состояние
    в двух местах. Первый ответ сервера при этом обычный HTML, поэтому
    ни поисковик, ни человек с выключенным JS ничего не теряют.
--}}
<livewire:catalog-filters :brand="$brand" :model="$model" />

@endsection
