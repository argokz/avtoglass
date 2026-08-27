@extends('layouts.app')

@section('title', 'Каталог автостекла по маркам автомобилей')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Каталог</span>
@endsection

@section('content')

<h1 class="text-2xl font-bold sm:text-3xl">Все марки</h1>
<p class="mt-1.5 text-sm text-muted">Выберите марку автомобиля — покажем модели, на которые есть стекло.</p>

<div class="mt-6 grid grid-cols-2 gap-2.5 sm:grid-cols-4 sm:gap-3 lg:grid-cols-6">
    @foreach ($popular as $brand)
        <a href="{{ route('catalog.models', $brand) }}"
           class="group flex items-center gap-3 rounded-card border border-line bg-surface p-4 transition
                  hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-card">
            {{-- Логотипы заливают вручную, и их пока нет у большинства марок.
                 Плитка обязана выглядеть законченной и без него. --}}
            @if ($brand->logoUrl())
                <img src="{{ $brand->logoUrl() }}" alt="" loading="lazy" decoding="async"
                     class="h-8 w-8 shrink-0 object-contain">
            @endif

            <span class="min-w-0">
                <span class="block truncate font-display font-bold transition group-hover:text-brand">
                    {{ $brand->name }}
                </span>
                <span class="mt-0.5 block text-xs text-faint">{{ $brand->models_count }} моделей</span>
            </span>
        </a>
    @endforeach
</div>

{{-- Быстрый переход по алфавиту: на телефоне список марок иначе листается долго. --}}
<nav class="scroll-row mt-8 border-y border-line py-2.5" aria-label="Марки по алфавиту">
    @foreach ($brands->keys() as $letter)
        <a href="#letter-{{ $loop->index }}"
           class="grid h-8 min-w-8 place-items-center rounded-lg px-2 text-sm font-bold text-muted
                  transition hover:bg-sunken hover:text-brand">
            {{ $letter }}
        </a>
    @endforeach
</nav>

<div class="mt-6 space-y-7">
    @foreach ($brands as $letter => $group)
        <section id="letter-{{ $loop->index }}" class="scroll-mt-32">
            <h2 class="mb-2.5 flex items-center gap-3 font-display text-sm font-extrabold text-brand">
                {{ $letter }}
                <span class="h-px flex-1 bg-line"></span>
            </h2>

            <ul class="grid gap-x-6 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($group as $brand)
                    <li>
                        <a href="{{ route('catalog.models', $brand) }}"
                           class="flex items-baseline justify-between gap-2 rounded-lg px-2 py-1.5 text-sm
                                  transition hover:bg-sunken hover:text-brand">
                            <span class="font-medium">{{ $brand->name }}</span>
                            <span class="text-xs text-faint">{{ $brand->models_count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>

@endsection
