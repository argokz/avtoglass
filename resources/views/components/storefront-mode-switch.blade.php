@props(['chat' => false])

<nav aria-label="Способ подбора" class="inline-flex rounded-pill border border-line bg-sunken p-1">
    @foreach (['store' => 'Магазин', 'chat' => 'ИИ-помощник'] as $mode => $label)
        <form action="{{ route('storefront.mode') }}" method="post">
            @csrf
            <input type="hidden" name="mode" value="{{ $mode }}">
            <button type="submit"
                    @if (($mode === 'chat') === $chat) aria-current="page" @endif
                    @class([
                        'inline-flex min-h-11 items-center justify-center rounded-pill px-5 text-sm font-bold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
                        'bg-surface text-ink shadow-card' => ($mode === 'chat') === $chat,
                        'text-muted hover:bg-surface/60 hover:text-ink' => ($mode === 'chat') !== $chat,
                    ])>{{ $label }}</button>
        </form>
    @endforeach
</nav>
