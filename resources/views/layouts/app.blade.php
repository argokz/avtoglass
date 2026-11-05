<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f8">

    <title>@yield('title', settings()->brandName().' — автостекло')</title>
    <meta name="description" content="@yield('description', 'Автостекло с установкой: лобовые, задние и боковые стёкла для всех марок. Подбор по автомобилю, цены нескольких поставщиков.')">

    <meta property="og:site_name" content="{{ settings()->brandName() }}">
    <meta property="og:title" content="@yield('title', settings()->brandName().' — автостекло')">
    <meta property="og:description" content="@yield('description', 'Автостекло с установкой: лобовые, задние и боковые стёкла для всех марок.')">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="ru_KZ">
    {{-- Канонический адрес без query: фильтры и пагинация не должны плодить дубли в выдаче --}}
    <link rel="canonical" href="@yield('canonical', url()->current())">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @endif

    <script type="application/ld+json">{!! \Illuminate\Support\Js::encode([
        '@context' => 'https://schema.org', '@type' => 'AutoRepair',
        'name' => settings()->brandName(), 'description' => 'Продажа и установка автостёкол в Казахстане',
        'url' => url('/'), 'telephone' => settings()->phones()[0]['display'] ?? '',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => settings()->address(), 'addressCountry' => 'KZ'],
        'priceRange' => '₸₸', 'openingHours' => settings()->hours(),
    ]) !!}</script>
    @stack('jsonld')

    <link rel="manifest" href="{{ asset('manifest.json') }}">
    {{-- SW только у полевой панели. Старую регистрацию на витрине снимаем: она ломала чат. --}}
    <script>
        if ('serviceWorker' in navigator) {
            @if (request()->routeIs('filament.field.*'))
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register(@json(asset('sw.js'))).catch(() => {});
                });
            @else
                navigator.serviceWorker.getRegistrations().then((regs) => {
                    regs.forEach((reg) => reg.unregister());
                });
            @endif
        }
    </script>

    {!! шрифты() !!}
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="storefront flex min-h-full flex-col @yield('body_class')">

@php
    $isCustomer = auth()->check() && ! auth()->user()->isStaff();
    $accountHref = $isCustomer
        ? route('account.orders')
        : route('account.login');
    $accountLabel = $isCustomer ? 'Кабинет' : 'Войти';
    $phones = settings()->phones();
    $primaryPhone = $phones[0] ?? ['display' => '', 'tel' => ''];
    $опт = текущийПокупатель()?->seesWholesalePrices();
    $чатГлавная = settings()->aiChatEnabled() && request()->routeIs('assistant');
@endphp

<a href="#content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50
                          focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-on-brand">
    Перейти к содержимому
</a>

@include('partials.storefront-header')

<main id="content" @class([
    'mx-auto w-full flex-1',
    'flex max-w-7xl flex-col px-4 py-4 sm:py-6' => $чатГлавная,
    'max-w-7xl px-4 py-5 sm:py-8' => ! $чатГлавная,
])>
    @hasSection('breadcrumbs')
        <nav aria-label="Хлебные крошки" class="mb-5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-faint">
            <a href="{{ route('home') }}" class="transition hover:text-ink">Главная</a>
            @yield('breadcrumbs')
        </nav>
    @endif

    @yield('content')
</main>

<footer @class(['bg-night text-on-night', 'mt-6' => $чатГлавная, 'mt-16' => ! $чатГлавная])>
    @unless ($чатГлавная)
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <x-logo size="lg" :on-night="true" />
            <p class="mt-4 text-sm leading-relaxed text-on-night-muted">
                Подбор, продажа и установка автостекла.
                {{ implode(', ', settings()->cities()) }}.
            </p>
        </div>

        <div>
            <h2 class="text-xs font-bold tracking-wider uppercase text-on-night">Каталог</h2>
            <ul class="mt-4 space-y-2.5 text-sm text-on-night-muted">
                <li><a href="{{ route('catalog.brands') }}" class="transition hover:text-on-night">Все марки</a></li>
                <li><a href="{{ route('catalog.consumables') }}" class="transition hover:text-on-night">Молдинги и клей</a></li>
                <li><a href="{{ route('search') }}" class="transition hover:text-on-night">Поиск по еврокоду</a></li>
                @if ($isCustomer)
                    <li><a href="{{ route('account.orders') }}" class="transition hover:text-on-night">Кабинет</a></li>
                @else
                    <li><a href="{{ route('account.login') }}" class="transition hover:text-on-night">Вход для клиентов</a></li>
                @endif
                <li><a href="{{ route('orders.lookup') }}" class="transition hover:text-on-night">Найти заявку без входа</a></li>
                @if (settings()->enabled('booking'))
                    <li><a href="{{ route('booking.form') }}" class="transition hover:text-on-night">Запись на установку</a></li>
                @endif
                @if (settings()->enabled('vin_lookup'))
                    <li><a href="{{ route('vin.form') }}" class="transition hover:text-on-night">Подбор по VIN</a></li>
                @endif
            </ul>
        </div>

        <div>
            <h2 class="text-xs font-bold tracking-wider uppercase text-on-night">Контакты</h2>
            <ul class="mt-4 space-y-2.5 text-sm text-on-night-muted">
                @foreach (settings()->phones() as $phone)
                    <li>
                        <a href="tel:{{ $phone['tel'] }}" class="font-semibold text-on-night transition hover:opacity-80">
                            {{ $phone['display'] }}
                        </a>
                    </li>
                @endforeach
                <li><a href="mailto:{{ settings()->email() }}" class="transition hover:text-on-night">{{ settings()->email() }}</a></li>
                @if (settings()->enabled('whatsapp') && settings()->whatsapp())
                    <li>
                        <a href="https://wa.me/{{ settings()->whatsapp() }}" target="_blank" rel="noopener"
                           class="transition hover:text-on-night">WhatsApp</a>
                    </li>
                @endif
                @if (settings()->instagram())
                    <li>
                        <a href="https://instagram.com/{{ settings()->instagram() }}" target="_blank" rel="noopener"
                           class="transition hover:text-on-night">Instagram</a>
                    </li>
                @endif
                <li class="pt-1">{{ settings()->address() }}</li>
                <li>{{ settings()->hours() }}</li>
            </ul>
        </div>

        <div class="text-sm text-on-night-muted">
            <h2 class="text-xs font-bold tracking-wider uppercase text-on-night">О заказе</h2>
            <p class="mt-4 leading-relaxed">
                Цена стекла и работы — как в карточке.
                Наличие и срок подтверждает менеджер.
            </p>
        </div>
    </div>
    @endunless

    <div @class(['border-white/10', 'border-t' => ! $чатГлавная])>
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-5 text-xs text-on-night-muted">
            <span>© {{ date('Y') }} {{ settings()->brandName() }}</span>
            @if ($чатГлавная)
                <span aria-hidden="true">·</span>
                <a href="{{ route('catalog.brands') }}" class="transition hover:text-on-night">Каталог</a>
            @endif
            <span aria-hidden="true">·</span>
            @if ($isCustomer)
                <a href="{{ route('account.orders') }}" class="transition hover:text-on-night">Кабинет</a>
            @else
                <a href="{{ route('orders.lookup') }}" class="transition hover:text-on-night">Мои заявки</a>
            @endif
            <span aria-hidden="true">·</span>
            <a href="{{ route('privacy') }}" class="transition hover:text-on-night">Персональные данные</a>
            <x-shop.theme-switch class="sm:ml-auto" />
        </div>
    </div>
</footer>

{{-- Нижняя навигация на телефоне. На страницах со своей панелью (товар, корзина) не выводится. --}}
@unless (str_contains((string) $__env->yieldContent('body_class'), 'has-mobile-cta') || $чатГлавная)
    @include('partials.mobile-tabbar')
@endunless

@if (settings()->enabled('cart'))
    <x-quick-order-modal />
@endif

{{-- Toast-уведомления --}}
<div id="toast-root" class="toast-container" aria-live="polite" aria-atomic="true"
     @if (session('success')) data-flash-success="{{ session('success') }}" @endif
     @if (session('error') || session('status') === 'error') data-flash-error="{{ session('error') ?? 'Произошла ошибка' }}" @endif
     @if (session('cart_added')) data-flash-success="Добавлено в корзину" @endif>
</div>

@stack('scripts')

</body>
</html>
