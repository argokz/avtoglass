<header class="storefront-header sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur-xl">
    <div class="storefront-toolbar mx-auto flex max-w-7xl items-center gap-2 px-4 py-2 sm:gap-4 sm:py-3">
        <a href="{{ route('home') }}" aria-label="{{ settings()->brandName() }} — на главную" class="storefront-brand-link min-w-0 shrink">
            <x-logo />
        </a>

        @unless ($чатГлавная)
            <form action="{{ route('search') }}" method="get" class="hidden min-w-0 flex-1 lg:block" role="search">
                <label class="sr-only" for="header-search">Марка, модель или еврокод</label>
                <div class="relative" data-suggest-root>
                    <input id="header-search" type="search" name="q" value="{{ request('q') }}"
                           placeholder="Марка, модель или еврокод" class="input !rounded-pill !bg-sunken !pr-24"
                           autocomplete="off" data-suggest="{{ route('api.suggest') }}"
                           role="combobox" aria-expanded="false" aria-controls="header-search-suggest">
                    <button type="submit" class="btn btn-primary btn-sm absolute top-1 right-1 min-h-11">Найти</button>
                    <div id="header-search-suggest" role="listbox" data-suggest-box
                         class="absolute inset-x-0 top-full z-50 mt-2 hidden overflow-hidden rounded-card border border-line bg-surface shadow-lift"></div>
                </div>
            </form>
        @endunless

        <nav aria-label="Основная навигация" class="ml-auto flex shrink-0 items-center gap-1">
            <a href="{{ route('catalog.brands') }}" @if (request()->routeIs('catalog.*')) aria-current="page" @endif class="hidden min-h-11 items-center rounded-pill px-3 text-sm font-semibold hover:bg-sunken md:inline-flex">Каталог</a>
            @if ($опт)
                <a href="{{ route('wholesale.index') }}" class="hidden min-h-11 items-center rounded-pill px-3 text-sm font-semibold hover:bg-sunken md:inline-flex">Оптовая закупка</a>
            @endif
            <a href="{{ $accountHref }}" class="hidden min-h-11 items-center gap-1.5 rounded-pill px-3 text-sm font-semibold hover:bg-sunken md:inline-flex">
                {{ $accountLabel }}
                @if ($опт)
                    <span class="rounded-pill bg-brand-soft px-1.5 py-0.5 text-xs text-brand-ink">Опт</span>
                @endif
            </a>
            @if (settings()->enabled('cart'))
                @php $cartCount = app(App\Cart\Cart::class)->count(); @endphp
                <a href="{{ route('cart.show') }}" aria-label="Корзина{{ $cartCount ? " ({$cartCount})" : '' }}"
                   class="relative inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-pill px-3 text-sm font-bold hover:bg-sunken">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 3h2l2.2 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20 7H5.5"/>
                        <circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>
                    </svg>
                    <span class="hidden xl:inline">Корзина</span>
                    @if ($cartCount > 0)
                        <span class="grid h-5 min-w-5 place-items-center rounded-pill bg-brand px-1 text-xs text-on-brand">{{ $cartCount }}</span>
                    @endif
                </a>
            @endif
            <details class="nav-menu relative md:hidden">
                <summary class="grid h-11 w-11 cursor-pointer place-items-center rounded-pill hover:bg-sunken" aria-label="Меню">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <nav aria-label="Мобильная навигация" class="absolute right-0 z-50 mt-2 w-64 rounded-card border border-line bg-surface p-2 shadow-lift">
                    <a href="{{ route('catalog.brands') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Каталог</a>
                    <a href="{{ $accountHref }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">{{ $accountLabel }}</a>
                    <a href="{{ route('orders.lookup') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Найти заявку</a>
                    @if ($опт)
                        <a href="{{ route('wholesale.index') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Оптовая закупка</a>
                    @endif
                    @if (settings()->enabled('booking'))
                        <a href="{{ route('booking.form') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Запись на установку</a>
                    @endif
                    @if (settings()->enabled('vin_lookup'))
                        <a href="{{ route('vin.form') }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Подбор по VIN</a>
                    @endif
                    @if ($primaryPhone['tel'])
                        <a href="tel:{{ $primaryPhone['tel'] }}" class="flex min-h-11 items-center rounded-xl px-3 font-semibold hover:bg-sunken">Позвонить</a>
                    @endif
                </nav>
            </details>
        </nav>
    </div>

    <div class="storefront-subnav border-t border-line">
        <div class="storefront-subnav-inner mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-2">
            @if (settings()->aiChatEnabled())
                <x-storefront-mode-switch :chat="$чатГлавная" />
            @else
                <a href="{{ route('catalog.brands') }}" class="inline-flex min-h-11 items-center font-bold">Каталог автостёкол</a>
            @endif
            <div class="storefront-utilities flex items-center gap-3 sm:gap-5">
                @if (settings()->enabled('booking'))
                    <a href="{{ route('booking.form') }}" class="hidden min-h-11 items-center text-sm font-semibold hover:text-brand-ink lg:inline-flex">Установка</a>
                @endif
                @if ($primaryPhone['tel'])
                    <a href="tel:{{ $primaryPhone['tel'] }}" class="hidden min-h-11 items-center text-sm font-bold hover:text-brand-ink lg:inline-flex">{{ $primaryPhone['display'] }}</a>
                @endif
                @if (count(settings()->cities()) > 1)
                    <form action="{{ route('city.select') }}" method="post">
                        @csrf
                        <label class="sr-only" for="city-select">Город</label>
                        <select id="city-select" name="city" onchange="this.form.submit()" class="min-h-11 max-w-36 rounded-pill border border-line bg-surface px-3 text-sm">
                            @foreach (settings()->cities() as $city)
                                <option value="{{ $city }}" @selected(currentCity() === $city)>{{ $city }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" class="btn btn-outline btn-sm">Выбрать</button></noscript>
                    </form>
                @else
                    <span class="text-xs text-muted sm:text-sm">{{ currentCity() }}</span>
                @endif
            </div>
        </div>
    </div>
    @unless ($чатГлавная)
        <div class="px-4 pb-3 lg:hidden">
            <form action="{{ route('search') }}" method="get" role="search" class="relative" data-suggest-root>
                <label class="sr-only" for="header-search-mobile">Марка, модель или еврокод</label>
                <input id="header-search-mobile" type="search" name="q" value="{{ request('q') }}" placeholder="Марка, модель или еврокод" class="input !rounded-pill !bg-sunken !pr-24"
                       autocomplete="off" data-suggest="{{ route('api.suggest') }}"
                       role="combobox" aria-expanded="false" aria-controls="header-search-mobile-suggest">
                <button type="submit" class="btn btn-primary btn-sm absolute top-1 right-1 min-h-11">Найти</button>
                <div id="header-search-mobile-suggest" role="listbox" data-suggest-box
                     class="absolute inset-x-0 top-full z-50 mt-2 hidden overflow-hidden rounded-card border border-line bg-surface shadow-lift"></div>
            </form>
        </div>
    @endunless
</header>
