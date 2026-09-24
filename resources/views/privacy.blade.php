@extends('layouts.app')

@section('title', 'Политика персональных данных — '.settings()->brandName())
@section('description', 'Как мы собираем и обрабатываем персональные данные по заявкам на автостекло.')

@section('breadcrumbs')
    <span class="text-line-strong">/</span>
    <span class="text-ink">Персональные данные</span>
@endsection

@section('content')

<article class="mx-auto max-w-3xl">
    <h1 class="text-2xl font-bold sm:text-3xl">Политика персональных данных</h1>
    <p class="mt-2 text-sm text-muted">{{ $company }}</p>

    <div class="prose-policy mt-8 space-y-4 text-sm leading-relaxed text-ink sm:text-base">
        @foreach (preg_split("/\R{2,}/u", trim($policy)) as $block)
            @php($lines = preg_split("/\R/u", trim($block)) ?: [])
            @if (count($lines) === 1 && ! str_starts_with($lines[0], '•') && mb_strlen($lines[0]) < 80)
                <h2 class="pt-2 text-lg font-bold">{{ $lines[0] }}</h2>
            @else
                <div class="space-y-1.5">
                    @foreach ($lines as $line)
                        <p>{{ $line }}</p>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</article>

@endsection
