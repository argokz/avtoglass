@php
    /** @var string $id */
    /** @var string $выбранныйСпособ */
    /** @var string $выбраннаяОплата */
    /** @var \App\Models\Service|null $выезд */
    /** @var string|null $адресПоУмолчанию */
    /** @var bool $withFulfillment */
@endphp

@if ($withFulfillment)
    <fieldset>
        <legend class="mb-3 text-sm font-extrabold tracking-tight">Как удобнее получить</legend>
        <div class="grid gap-2 sm:grid-cols-2" data-fulfillment>
            @foreach (App\Enums\FulfillmentType::cases() as $type)
                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-line
                              bg-surface p-3.5 text-sm transition hover:border-brand/40
                              has-checked:border-brand has-checked:bg-brand-soft">
                    <input type="radio" name="fulfillment" value="{{ $type->value }}"
                           @checked($выбранныйСпособ === $type->value)
                           data-needs-address="{{ $type->needsAddress() ? '1' : '' }}"
                           data-needs-slot="{{ $type->needsSlot() ? '1' : '' }}"
                           class="mt-1 accent-brand">
                    <span class="min-w-0">
                        <span class="block font-bold leading-snug">{{ $type->label() }}</span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-muted">{{ $type->description() }}</span>
                        @if ($type === App\Enums\FulfillmentType::Visit && $выезд)
                            <span class="mt-1 block text-xs font-semibold text-brand-ink">
                                + {{ number_format((float) $выезд->price, 0, ',', ' ') }} ₸ за выезд
                            </span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>

    <div data-address-field @class(['hidden' => ! in_array($выбранныйСпособ, ['delivery', 'visit'], true)])>
        <label for="of-addr-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">Адрес</label>
        <input id="of-addr-{{ $id }}" type="text" name="address" maxlength="255"
               value="{{ old('address', $адресПоУмолчанию) }}"
               placeholder="Город, улица, дом, ориентир"
               class="input @error('address') input-error @enderror">
        @if ($адресПоУмолчанию && ! old('address'))
            <p class="mt-1 text-xs text-ok-ink">Адрес из прошлого заказа — можно поправить</p>
        @else
            <p class="mt-1 text-xs text-muted">Сейчас город витрины — {{ currentCity() }}. Сменить можно в шапке.</p>
        @endif
        @error('address')
            <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
        @enderror
    </div>

    <div data-installation-field @class(['space-y-3', 'hidden' => ! in_array($выбранныйСпособ, ['installation', 'visit'], true)])>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="of-date-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">Желаемая дата</label>
                <input id="of-date-{{ $id }}" type="date" name="preferred_date" min="{{ date('Y-m-d') }}" value="{{ old('preferred_date') }}"
                       class="input">
            </div>
            <div>
                <label for="of-time-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">Желаемое время</label>
                <select id="of-time-{{ $id }}" name="preferred_time" class="select">
                    <option value="">Любое время в течение дня</option>
                    <option value="09:00 - 11:00" @selected(old('preferred_time') === '09:00 - 11:00')>09:00 – 11:00</option>
                    <option value="11:30 - 13:30" @selected(old('preferred_time') === '11:30 - 13:30')>11:30 – 13:30</option>
                    <option value="14:00 - 16:00" @selected(old('preferred_time') === '14:00 - 16:00')>14:00 – 16:00</option>
                    <option value="16:30 - 18:30" @selected(old('preferred_time') === '16:30 - 18:30')>16:30 – 18:30</option>
                    <option value="18:30 - 20:00" @selected(old('preferred_time') === '18:30 - 20:00')>18:30 – 20:00</option>
                </select>
            </div>
        </div>
        <p class="rounded-2xl border border-line bg-sunken px-3.5 py-2.5 text-xs leading-relaxed text-muted">
            Время предварительное. Менеджер подтвердит слот и наличие стекла.
        </p>
    </div>
@endif

@if (settings()->enabled('payments'))
    <fieldset>
        <legend class="mb-3 text-sm font-extrabold tracking-tight">Оплата</legend>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach (App\Enums\PaymentMethod::cases() as $method)
                @continue($method === App\Enums\PaymentMethod::Invoice && ! (текущийПокупатель()?->seesWholesalePrices() ?? false))
                @continue(\App\Domain\Ordering\StorefrontCheckout::enabled() && ! array_key_exists($method->value, \App\Domain\Ordering\PaymentOptions::available(\App\Domain\Identity\SessionBuyer::current())))
                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-line
                              bg-surface p-3.5 text-sm transition hover:border-brand/40
                              has-checked:border-brand has-checked:bg-brand-soft">
                    <input type="radio" name="payment_method" value="{{ $method->value }}"
                           @checked($выбраннаяОплата === $method->value)
                           class="mt-1 accent-brand">
                    <span class="min-w-0">
                        <span class="block font-bold leading-snug">{{ $method->label() }}</span>
                        <span class="mt-0.5 block text-xs leading-relaxed text-muted">{{ $method->description() }}</span>
                        @if ($method === App\Enums\PaymentMethod::Card && config('payments.halyk.test_mode'))
                            <span class="mt-1 block text-xs font-semibold text-warn-ink">Тестовый терминал — деньги не списываются</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif
