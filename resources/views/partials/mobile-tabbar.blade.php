{{--
    Нижняя навигация на телефоне: основной трафик мобильный, и путь
    «подбор → поиск → корзина → чат» обязан быть под большим пальцем,
    а не спрятан в бургере. На страницах со своей нижней панелью
    (карточка товара, корзина) не выводится — там она бы перекрыла CTA.
--}}
@php
    $tabCartCount = settings()->enabled('cart') ? app(App\Cart\Cart::class)->count() : 0;
    $tabs = [
        [
            'href' => route('catalog.brands'),
            'label' => 'Каталог',
            'active' => request()->routeIs('catalog.*'),
            'icon' => 'M4 6h16M4 12h16M4 18h10',
        ],
        [
            'href' => route('search'),
            'label' => 'Поиск',
            'active' => request()->routeIs('search'),
            'icon' => 'M21 21l-4.8-4.8M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z',
        ],
        [
            'href' => route('home'),
            'label' => 'Подбор',
            'active' => request()->routeIs('home'),
            'icon' => 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10',
        ],
        [
            'href' => route('cart.show'),
            'label' => 'Корзина',
            'active' => request()->routeIs('cart.*'),
            'icon' => 'M2.5 3h2l2.2 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20 7H5.5',
            'count' => $tabCartCount,
        ],
    ];

    if (settings()->aiChatEnabled()) {
        $tabs[] = [
            'href' => route('assistant'),
            'label' => 'Чат',
            'active' => request()->routeIs('assistant'),
            'icon' => 'M21 12a8 8 0 0 1-8 8H5l-2 2V12a8 8 0 0 1 8-8h2a8 8 0 0 1 8 8Z',
        ];
    }
@endphp

<nav aria-label="Быстрая навигация"
     class="mobile-tabbar fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 backdrop-blur lg:hidden"
     style="padding-bottom: env(safe-area-inset-bottom)">
    {{-- Колонки инлайном: Tailwind не соберёт класс, склеенный в рантайме --}}
    <ul class="grid list-none" style="grid-template-columns: repeat({{ count($tabs) }}, minmax(0, 1fr))">
        @foreach ($tabs as $tab)
            <li>
                <a href="{{ $tab['href'] }}"
                   @if ($tab['active']) aria-current="page" @endif
                   class="relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-semibold transition
                          {{ $tab['active'] ? 'text-brand-ink' : 'text-muted hover:text-ink' }}">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $tab['icon'] }}"/>
                    </svg>
                    {{ $tab['label'] }}
                    @if (($tab['count'] ?? 0) > 0)
                        <span class="absolute top-1 right-1/2 grid h-4 min-w-4 translate-x-5 place-items-center rounded-pill bg-brand px-1 text-[10px] text-on-brand">
                            {{ $tab['count'] }}
                        </span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
