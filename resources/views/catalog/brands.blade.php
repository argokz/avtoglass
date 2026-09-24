@extends('layouts.app')

@section('title', 'Каталог автостекла по маркам автомобилей')

@section('breadcrumbs')
    <span class="text-line-strong">/</span><span class="text-ink">Каталог</span>
@endsection

@section('content')

<h1 class="text-2xl font-extrabold sm:text-3xl">Какая марка?</h1>
<p class="mt-1.5 text-sm text-muted">
    @if ($glassType)
        Ищете {{ mb_strtolower($glassType->label()) }} — выберите марку, на странице модели отфильтруем.
    @else
        Выберите марку — дальше модель, год и стекло.
    @endif
</p>

<div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
    @foreach ($popular as $brand)
        <a href="{{ route('catalog.models', array_filter(['brand' => $brand, 'type' => $glassType?->value])) }}"
           class="card card-hover group flex items-center gap-3 p-4">
            @if ($brand->logoUrl())
                <img src="{{ $brand->logoUrl() }}" alt="" loading="lazy" decoding="async"
                     class="h-9 w-9 shrink-0 object-contain">
            @else
                <x-brand-logo :brand="$brand" class="h-9 w-9 shrink-0 text-ink" />
            @endif

            <span class="min-w-0">
                <span class="block truncate font-display font-extrabold transition group-hover:text-brand-ink">
                    {{ $brand->name }}
                </span>
                <span class="mt-0.5 block text-xs text-faint">{{ $brand->models_count }} {{ склонение($brand->models_count, 'модель', 'модели', 'моделей') }}</span>
            </span>
        </a>
    @endforeach
</div>

<nav class="scroll-row mt-8 border-y border-line py-3" aria-label="Марки по алфавиту">
    @foreach ($brands->keys() as $letter)
        <a href="#letter-{{ $loop->index }}"
           class="chip !min-w-9 !justify-center !px-2.5 !py-1.5">
            {{ $letter }}
        </a>
    @endforeach
</nav>

<div class="mt-6 space-y-8">
    @foreach ($brands as $letter => $group)
        <section id="letter-{{ $loop->index }}" class="scroll-mt-32 reveal">
            <h2 class="mb-3 flex items-center gap-3 font-display text-sm font-extrabold text-brand-ink">
                {{ $letter }}
                <span class="h-px flex-1 bg-line"></span>
            </h2>

            <ul class="grid gap-x-6 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($group as $brand)
                    <li>
                        <a href="{{ route('catalog.models', array_filter(['brand' => $brand, 'type' => $glassType?->value])) }}"
                           class="flex items-baseline justify-between gap-2 rounded-xl px-2.5 py-2 text-sm
                                  transition hover:bg-sunken hover:text-brand-ink">
                            <span class="font-semibold">{{ $brand->name }}</span>
                            <span class="text-xs text-faint">{{ $brand->models_count }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>

@endsection
