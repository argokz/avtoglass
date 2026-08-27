<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- цвет системной панели браузера подстраивается под тему --}}
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#14181f" media="(prefers-color-scheme: dark)">

    <title>@yield('title', config('company.name').' — автостекло в '.config('company.legal_city'))</title>
    <meta name="description" content="@yield('description', 'Автостекло с установкой: лобовые, задние и боковые стёкла для всех марок. Подбор по автомобилю, цены нескольких поставщиков.')">

    {{-- @fonts подключает самохостящиеся шрифты и preload-ссылки к ним --}}
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col">

<a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50
                          focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-on-brand">
    Перейти к содержимому
</a>

<header class="sticky top-0 z-40 border-b border-line bg-surface/85 backdrop-blur-md">
    <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:gap-5">

        <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-brand text-on-brand">
                <x-glass-icon type="windshield" class="h-5 w-5" />
            </span>
            <span class="font-display text-lg leading-none font-extrabold tracking-tight">
                Авто<span class="text-brand">стекло</span>
            </span>
        </a>

        <form action="{{ route('search') }}" method="get" class="hidden flex-1 lg:block" role="search">
            <div class="relative">
                <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-faint"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
                </svg>
                <input type="search" name="q" value="{{ request('q') }}"
                       placeholder="Марка, модель или еврокод со стекла — 7260AGNGYV"
                       class="w-full rounded-pill border border-line bg-sunken py-2.5 pr-24 pl-10 text-sm
                              placeholder:text-faint focus:border-brand focus:bg-surface focus:outline-none">
                <button class="absolute top-1 right-1 rounded-pill bg-brand px-5 py-1.5 text-sm font-semibold
                               text-on-brand transition hover:bg-brand-hover">
                    Найти
                </button>
            </div>
        </form>

        <nav class="ml-auto flex shrink-0 items-center gap-1 sm:gap-3">
            <a href="{{ route('catalog.brands') }}"
               class="hidden rounded-pill px-3 py-2 text-sm font-medium text-muted transition hover:bg-sunken hover:text-ink sm:block">
                Каталог
            </a>

            @php $cartCount = app(App\Cart\Cart::class)->count(); @endphp
            <a href="{{ route('cart.show') }}" aria-label="Корзина"
               class="relative grid h-10 w-10 place-items-center rounded-pill transition hover:bg-sunken">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.5 3h2l2.2 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20 7H5.5"/>
                    <circle cx="9.5" cy="19.5" r="1.3"/><circle cx="17" cy="19.5" r="1.3"/>
                </svg>
                @if ($cartCount > 0)
                    <span class="absolute -top-0.5 -right-0.5 grid h-5 min-w-5 place-items-center rounded-pill
                                 bg-brand px-1 text-[11px] leading-none font-bold text-on-brand">
                        {{ $cartCount }}
                    </span>
                @endif
            </a>

            <a href="tel:{{ config('company.phones.0.tel') }}"
               class="hidden items-center gap-2 rounded-pill bg-sunken px-4 py-2 text-sm font-semibold whitespace-nowrap md:flex">
                <svg class="h-4 w-4 text-brand" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.5 5.5c0-1 .8-1.8 1.8-1.8h2c.8 0 1.5.5 1.7 1.3l.8 2.7c.2.7 0 1.4-.6 1.8l-1.3 1a13 13 0 0 0 6 6l1-1.3c.4-.6 1.1-.8 1.8-.6l2.7.8c.8.2 1.3.9 1.3 1.7v2c0 1-.8 1.8-1.8 1.8A16.5 16.5 0 0 1 2.5 5.5Z"/>
                </svg>
                {{ config('company.phones.0.display') }}
            </a>

            {{-- на телефоне звонок важнее любого пункта меню --}}
            <a href="tel:{{ config('company.phones.0.tel') }}" aria-label="Позвонить"
               class="grid h-10 w-10 place-items-center rounded-pill bg-brand text-on-brand md:hidden">
                <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M2.5 5.5c0-1 .8-1.8 1.8-1.8h2c.8 0 1.5.5 1.7 1.3l.8 2.7c.2.7 0 1.4-.6 1.8l-1.3 1a13 13 0 0 0 6 6l1-1.3c.4-.6 1.1-.8 1.8-.6l2.7.8c.8.2 1.3.9 1.3 1.7v2c0 1-.8 1.8-1.8 1.8A16.5 16.5 0 0 1 2.5 5.5Z"/>
                </svg>
            </a>
        </nav>
    </div>

    <div class="border-t border-line px-4 py-2 lg:hidden">
        <form action="{{ route('search') }}" method="get" role="search">
            <div class="relative">
                <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-faint"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
                </svg>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Поиск по каталогу"
                       class="w-full rounded-pill border border-line bg-sunken py-2.5 pr-4 pl-10 text-sm
                              placeholder:text-faint focus:border-brand focus:bg-surface focus:outline-none">
            </div>
        </form>
    </div>
</header>

<main id="content" class="mx-auto w-full max-w-7xl flex-1 px-4 py-5 sm:py-8">
    @hasSection('breadcrumbs')
        <nav aria-label="Хлебные крошки" class="mb-4 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-faint">
            <a href="{{ route('home') }}" class="transition hover:text-ink">Главная</a>
            @yield('breadcrumbs')
        </nav>
    @endif

    @yield('content')
</main>

<footer class="mt-12 bg-night text-on-night">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="grid h-9 w-9 place-items-center rounded-xl bg-brand text-on-brand">
                    <x-glass-icon type="windshield" class="h-5 w-5" />
                </span>
                <span class="font-display text-lg font-extrabold">{{ config('company.name') }}</span>
            </div>
            <p class="mt-3 text-sm text-on-night-muted">
                Подбор, продажа и установка автостекла.
                {{ implode(', ', config('company.cities')) }}.
            </p>
        </div>

        <div>
            <h2 class="text-sm font-semibold">Каталог</h2>
            <ul class="mt-3 space-y-2 text-sm text-on-night-muted">
                <li><a href="{{ route('catalog.brands') }}" class="transition hover:text-on-night">Все марки</a></li>
                <li><a href="{{ route('search') }}" class="transition hover:text-on-night">Поиск по еврокоду</a></li>
            </ul>
        </div>

        <div>
            <h2 class="text-sm font-semibold">Контакты</h2>
            <ul class="mt-3 space-y-2 text-sm text-on-night-muted">
                @foreach (config('company.phones') as $phone)
                    <li>
                        <a href="tel:{{ $phone['tel'] }}" class="transition hover:text-on-night">
                            {{ $phone['display'] }}
                        </a>
                    </li>
                @endforeach
                <li><a href="mailto:{{ config('company.email') }}" class="transition hover:text-on-night">{{ config('company.email') }}</a></li>
                @if (config('company.whatsapp'))
                    <li>
                        <a href="https://wa.me/{{ config('company.whatsapp') }}" target="_blank" rel="noopener"
                           class="transition hover:text-on-night">WhatsApp</a>
                    </li>
                @endif
                @if (config('company.instagram'))
                    <li>
                        <a href="https://instagram.com/{{ config('company.instagram') }}" target="_blank" rel="noopener"
                           class="transition hover:text-on-night">Instagram</a>
                    </li>
                @endif
                <li>{{ config('company.address') }}</li>
                <li>{{ config('company.hours') }}</li>
            </ul>
        </div>

        <div class="text-sm text-on-night-muted">
            <h2 class="text-sm font-semibold text-on-night">О ценах</h2>
            <p class="mt-3">
                Цены на сайте справочные. Наличие и окончательную стоимость с работами
                подтверждает менеджер.
            </p>
        </div>
    </div>

    <div class="border-t border-white/10">
        <div class="mx-auto max-w-7xl px-4 py-5 text-xs text-on-night-muted">
            © {{ date('Y') }} {{ config('company.name') }} · {{ config('company.address') }}
        </div>
    </div>
</footer>

{{--
    Плавающая кнопка WhatsApp.

    Стоит поверх всего и на каждой странице намеренно: половина обращений
    в этой нише начинается с фотографии разбитого стекла, а не с формы.
    Заранее подставленный текст экономит клиенту первую фразу и сразу
    говорит менеджеру, откуда человек пришёл.
--}}
@if (config('company.whatsapp'))
    <a href="https://wa.me/{{ config('company.whatsapp') }}?text={{ rawurlencode('Здравствуйте! Пишу с сайта, нужно стекло на ') }}"
       target="_blank" rel="noopener"
       aria-label="Написать в WhatsApp"
       class="fixed right-4 bottom-4 z-50 flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-3 text-sm font-semibold text-white shadow-lg transition hover:brightness-95 focus:ring-2 focus:ring-white focus:outline-none">
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M17.47 14.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.65.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.6-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.69.63.71.22 1.36.19 1.87.12.57-.09 1.75-.72 2-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35z"/>
            <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.86 9.86 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.13h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.36c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.83 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.7 8.24-8.24 8.24z"/>
        </svg>
        <span class="hidden sm:inline">Написать в WhatsApp</span>
    </a>
@endif

@stack('scripts')

</body>
</html>
