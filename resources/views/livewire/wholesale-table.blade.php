{{--
    Оптовая витрина: таблица с галочками, без корзины.

    Выбор живёт в компоненте, отправка — обычным POST: скрытые поля
    items[...][offer_id]/qty рендерятся в форме внизу. Так валидация одна —
    StoreWholesaleOrderRequest, — а Livewire отвечает только за удобство
    выбора: поиск, фильтры, итог.
--}}
<div class="pb-8">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold sm:text-3xl">Оптовая заявка</h1>
            <p class="mt-1 text-sm text-muted">
                Отметьте позиции галочками, проставьте количество — и отправьте одним списком.
            </p>
        </div>
        <div class="text-sm text-muted">
            {{ $customer?->company_name ?? $customer?->name }}
            @if ($customer?->wholesale_discount_percent)
                · скидка {{ (int) $customer->wholesale_discount_percent }}%
            @endif
        </div>
    </div>

    {{-- Поиск и фильтры --}}
    <div class="card mt-5 flex flex-wrap items-center gap-3 px-4 py-3.5">
        <div class="relative min-w-0 flex-1 sm:max-w-sm">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-muted"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/>
            </svg>
            <input type="search" wire:model.live.debounce.400ms="q"
                   placeholder="Еврокод, OEM, артикул или название"
                   class="input !rounded-pill !py-2.5 !pl-10 !text-sm">
        </div>

        <select wire:model.live="type" class="select !w-auto !rounded-pill !py-2 !text-sm !font-semibold">
            <option value="">Все типы</option>
            @foreach ($types as $glassType)
                @if ($glassType !== App\Enums\GlassType::Service)
                    <option value="{{ $glassType->value }}">{{ $glassType->label() }}</option>
                @endif
            @endforeach
        </select>

        <select wire:model.live="supplierId" class="select !w-auto !rounded-pill !py-2 !text-sm !font-semibold">
            <option value="0">Все марки стекла</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>

        <label class="flex cursor-pointer items-center gap-2 text-sm font-semibold">
            <input type="checkbox" wire:model.live="inStock" class="accent-brand">
            В наличии
        </label>
    </div>

    {{-- Таблица (десктоп) --}}
    <div class="card mt-4 hidden !p-0 md:block">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-line text-left text-xs text-muted">
                    <th class="px-4 py-3 font-bold"></th>
                    <th class="px-4 py-3 font-bold">Стекло</th>
                    <th class="px-4 py-3 font-bold">Марка</th>
                    <th class="px-4 py-3 font-bold">Наличие</th>
                    <th class="px-4 py-3 text-right font-bold">Цена</th>
                    <th class="px-4 py-3 text-center font-bold">Кол-во</th>
                    <th class="px-4 py-3 text-right font-bold">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($offers as $offer)
                    @php
                        $выбран = isset($checked[$offer->id]);
                        $цена = $offer->sellingPrice($customer);
                        $колво = (int) ($qty[$offer->id] ?? 1);
                    @endphp
                    <tr class="border-b border-line last:border-b-0 transition {{ $выбран ? 'bg-brand-soft' : 'hover:bg-sunken' }}">
                        <td class="px-4 py-3">
                            <input type="checkbox" wire:change="toggle({{ $offer->id }})"
                                   @checked($выбран) class="accent-brand"
                                   aria-label="Выбрать {{ $offer->product?->name }}">
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-bold leading-snug">{{ $offer->product?->name }}</div>
                            <div class="mt-0.5 font-mono text-xs text-faint">
                                {{ $offer->product?->eurocode }}
                                @if ($offer->product?->oem_code)
                                    · OEM {{ $offer->product->oem_code }}
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $offer->supplier?->name }}</td>
                        <td class="px-4 py-3">
                            @if ($offer->product?->in_stock)
                                <span class="badge badge-ok">В наличии</span>
                            @else
                                <span class="badge badge-muted">Под заказ</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap">
                            @if ($цена)
                                {{ number_format($цена, 0, ',', ' ') }} ₸
                            @else
                                <span class="text-faint">по запросу</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($выбран)
                                <input type="number" wire:model.live.debounce.400ms="qty.{{ $offer->id }}"
                                       min="1" max="{{ App\Cart\Cart::MAX_WHOLESALE_QUANTITY }}"
                                       aria-label="Количество"
                                       class="input !w-20 !rounded-xl !px-2 !py-1.5 !text-center !text-sm">
                            @else
                                <span class="text-faint">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-bold whitespace-nowrap">
                            @if ($выбран && $цена)
                                {{ number_format($цена * $колво, 0, ',', ' ') }} ₸
                            @else
                                <span class="text-faint">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-muted">
                            Ничего не нашлось. Попробуйте еврокод без пробелов или сбросьте фильтры.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Карточки (телефон) --}}
    <ul class="mt-4 space-y-2.5 md:hidden">
        @forelse ($offers as $offer)
            @php
                $выбран = isset($checked[$offer->id]);
                $цена = $offer->sellingPrice($customer);
                $колво = (int) ($qty[$offer->id] ?? 1);
            @endphp
            <li class="card p-3.5 {{ $выбран ? '!border-brand/50 bg-brand-soft' : '' }}">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" wire:change="toggle({{ $offer->id }})"
                           @checked($выбран) class="mt-1 accent-brand">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold leading-snug">{{ $offer->product?->name }}</span>
                        <span class="mt-0.5 block font-mono text-xs text-faint">
                            {{ $offer->product?->eurocode }}
                            @if ($offer->product?->oem_code)
                                · OEM {{ $offer->product->oem_code }}
                            @endif
                        </span>
                        <span class="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                            <span class="font-semibold text-ink">{{ $offer->supplier?->name }}</span>
                            @if ($offer->product?->in_stock)
                                <span class="badge badge-ok">В наличии</span>
                            @else
                                <span class="badge badge-muted">Под заказ</span>
                            @endif
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block font-bold whitespace-nowrap">
                            @if ($цена)
                                {{ number_format($цена, 0, ',', ' ') }} ₸
                            @else
                                <span class="text-faint">по запросу</span>
                            @endif
                        </span>
                    </span>
                </label>
                @if ($выбран)
                    <div class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3">
                        <input type="number" wire:model.live.debounce.400ms="qty.{{ $offer->id }}"
                               min="1" max="{{ App\Cart\Cart::MAX_WHOLESALE_QUANTITY }}"
                               aria-label="Количество"
                               class="input !w-24 !rounded-xl !px-2 !py-1.5 !text-center !text-sm">
                        <span class="font-bold">
                            @if ($цена)
                                {{ number_format($цена * $колво, 0, ',', ' ') }} ₸
                            @endif
                        </span>
                    </div>
                @endif
            </li>
        @empty
            <li class="card px-4 py-10 text-center text-sm text-muted">
                Ничего не нашлось. Попробуйте еврокод без пробелов или сбросьте фильтры.
            </li>
        @endforelse
    </ul>

    <div class="mt-4">
        {{ $offers->links() }}
    </div>

    {{-- Итог и оформление --}}
    @if ($totals['positions'] > 0)
        <div class="sticky bottom-3 z-30 mt-6">
            <div class="card flex flex-wrap items-center justify-between gap-3 !border-brand/40 bg-surface/95 px-4 py-3 shadow-lift backdrop-blur">
                <p class="text-sm">
                    Выбрано: <strong>{{ $totals['positions'] }}</strong> поз. ·
                    <strong>{{ $totals['pieces'] }}</strong> шт ·
                    <strong class="price price-brand">{{ number_format($totals['total'], 0, ',', ' ') }} ₸</strong>
                </p>
                <a href="#opt-form" class="btn btn-primary btn-sm !rounded-xl">Оформить заявку</a>
            </div>
        </div>

        <form id="opt-form" method="post" action="{{ route('wholesale.store') }}"
              class="card mt-4 scroll-mt-28 space-y-4 p-5 sm:p-6">
            @csrf
            <input type="hidden" name="form_token" value="{{ (string) Illuminate\Support\Str::uuid() }}">

            @foreach ($selection as $i => $offer)
                <input type="hidden" name="items[{{ $i }}][offer_id]" value="{{ $offer->id }}">
                <input type="hidden" name="items[{{ $i }}][qty]" value="{{ (int) ($qty[$offer->id] ?? 1) }}">
            @endforeach

            <div class="hidden" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            <h2 class="text-sm font-extrabold tracking-tight">Получение и отправка</h2>

            @if ($errors->any())
                <div class="rounded-2xl border border-warn/40 bg-warn-soft p-3.5 text-sm text-warn-ink">
                    <p class="font-bold">Заявку не отправили — проверьте форму</p>
                    <ul class="mt-1.5 list-disc pl-4">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <ul class="space-y-1.5 text-sm">
                @foreach ($selection as $offer)
                    <li class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate text-muted">
                            {{ $offer->product?->name }}
                            <span class="text-faint">· {{ $offer->supplier?->name }} × {{ (int) ($qty[$offer->id] ?? 1) }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            @php $цена = $offer->sellingPrice($customer); @endphp
                            @if ($цена)
                                <span class="font-semibold">{{ number_format($цена * (int) ($qty[$offer->id] ?? 1), 0, ',', ' ') }} ₸</span>
                            @endif
                            <button type="button" wire:click="remove({{ $offer->id }})"
                                    class="text-faint transition hover:text-brand-ink" aria-label="Убрать">
                                ✕
                            </button>
                        </span>
                    </li>
                @endforeach
            </ul>

            <fieldset>
                <legend class="mb-2 text-xs font-bold text-muted">Как заберёте</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-line bg-surface p-3.5 text-sm transition has-checked:border-brand has-checked:bg-brand-soft">
                        <input type="radio" name="fulfillment" value="pickup"
                               @checked(old('fulfillment', 'pickup') === 'pickup') class="mt-1 accent-brand">
                        <span>
                            <span class="block font-bold">Самовывоз</span>
                            <span class="mt-0.5 block text-xs text-muted">Заберёте со склада сами</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-line bg-surface p-3.5 text-sm transition has-checked:border-brand has-checked:bg-brand-soft">
                        <input type="radio" name="fulfillment" value="delivery"
                               @checked(old('fulfillment') === 'delivery') class="mt-1 accent-brand">
                        <span>
                            <span class="block font-bold">Доставка</span>
                            <span class="mt-0.5 block text-xs text-muted">Привезём, без установки</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div>
                <label for="opt-address" class="mb-1.5 block text-xs font-bold text-muted">
                    Адрес <span class="font-normal text-faint">— обязателен для доставки</span>
                </label>
                <input id="opt-address" type="text" name="address" maxlength="255"
                       value="{{ old('address', $customer?->default_address) }}"
                       placeholder="Город, улица, дом"
                       class="input @error('address') input-error @enderror">
                @error('address')<p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="opt-comment" class="mb-1.5 block text-xs font-bold text-muted">
                    Комментарий <span class="font-normal text-faint">— необязательно</span>
                </label>
                <textarea id="opt-comment" name="comment" rows="2" maxlength="2000"
                          placeholder="Сроки, упаковка, доставка в другой город"
                          class="input">{{ old('comment') }}</textarea>
            </div>

            @if (settings()->enabled('privacy_consent'))
                <label class="flex cursor-pointer items-start gap-2.5 text-sm leading-relaxed text-muted">
                    <input type="checkbox" name="privacy_consent" value="1" required
                           class="mt-0.5 accent-brand">
                    <span>
                        Соглашаюсь на обработку персональных данных —
                        <a href="{{ route('privacy') }}" target="_blank" class="font-semibold text-brand-ink underline-offset-4 hover:underline">политика</a>
                    </span>
                </label>
            @endif

            <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl">
                Отправить заявку · {{ number_format($totals['total'], 0, ',', ' ') }} ₸
            </button>
            <p class="text-center text-xs text-faint">
                Оплату предложит менеджер после рассмотрения: счёт, онлайн или при получении.
            </p>
        </form>
    @endif
</div>
