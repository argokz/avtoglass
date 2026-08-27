@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Постраничная навигация" class="flex items-center justify-between gap-3">

        @if ($paginator->onFirstPage())
            <span class="rounded-pill border border-line px-4 py-2 text-sm font-semibold text-faint">Назад</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                      transition hover:border-brand/40 hover:text-brand">Назад</a>
        @endif

        {{-- Номера страниц прячем на телефоне: 250 страниц туда всё равно не влезут. --}}
        <div class="hidden items-center gap-1 sm:flex">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 text-sm text-faint">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page"
                                  class="grid h-9 min-w-9 place-items-center rounded-lg bg-brand px-3
                                         text-sm font-bold text-on-brand">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}"
                               class="grid h-9 min-w-9 place-items-center rounded-lg px-3 text-sm font-semibold
                                      text-muted transition hover:bg-sunken hover:text-brand">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        <span class="text-sm text-faint sm:hidden">
            {{ $paginator->currentPage() }} из {{ $paginator->lastPage() }}
        </span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="rounded-pill border border-line bg-surface px-4 py-2 text-sm font-semibold
                      transition hover:border-brand/40 hover:text-brand">Вперёд</a>
        @else
            <span class="rounded-pill border border-line px-4 py-2 text-sm font-semibold text-faint">Вперёд</span>
        @endif
    </nav>
@endif
