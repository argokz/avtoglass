@extends('layouts.app')

@section('title', 'Подбор автостекла по автомобилю')
@section('description', 'Оставьте заявку — подберём стекло по марке, модели и VIN, назовём цену со стоимостью работ.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Заявка на подбор</span>
@endsection

@section('content')

<div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_420px]">
    <div>
        <h1 class="text-2xl font-bold sm:text-3xl">Подберём стекло по вашей машине</h1>
        <p class="mt-3 text-muted">
            Если не нашли нужное стекло в каталоге или сомневаетесь в комплектации —
            опишите машину, и мы подберём сами.
        </p>

        <div class="mt-6 space-y-3">
            @foreach ([
                ['Комплектация важнее года', 'У одной модели бывает несколько стёкол: с датчиком дождя, камерой, обогревом. По VIN мы определим вашу точно.'],
                ['Скажем цену целиком', 'Со стеклом, работой и калибровкой камеры, если она нужна. Без сюрпризов при выдаче.'],
                ['Найдём под заказ', 'Если стекла нет на складе — посмотрим у поставщиков и назовём срок.'],
            ] as [$title, $text])
                <div class="rounded-card border border-line bg-surface p-4">
                    <h2 class="font-bold">{{ $title }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-muted">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <aside>
        <div class="rounded-card border border-line bg-surface p-5 shadow-card lg:sticky lg:top-28">
            <h2 class="text-lg font-bold">Заявка на подбор</h2>
            <p class="mt-1 mb-4 text-sm text-muted">Ответим в рабочее время, обычно в течение часа</p>

            <x-order-form
                :action="route('order.selection')"
                submit="Подобрать стекло"
                :with-car="true"
                note="Перезвоним, уточним комплектацию и назовём цену" />
        </div>
    </aside>
</div>

@endsection
