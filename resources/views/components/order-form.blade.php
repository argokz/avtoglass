@props([
    'action',
    'title' => 'Оформить заявку',
    'submit' => 'Отправить заявку',
    'note' => 'Менеджер перезвонит, подтвердит наличие и назовёт срок',
    'withCar' => false,
    'carInfo' => null,
    'withFulfillment' => true,
    /** wide — две колонки на десктопе (корзина); compact — одна колонка */
    'layout' => 'compact',
    'formId' => null,
    'showSubmit' => true,
])

@php
    $вошёл = auth()->check() && ! auth()->user()->isStaff() ? auth()->user() : null;
    $карточка = $вошёл?->customer_id
        ? $вошёл->customer()->with('carModel.brand')->first()
        : null;
    $адресПоУмолчанию = $карточка?->default_address;
    $машинаПоУмолчанию = $карточка?->carLabel()
        ?: ($carInfo ?: '')
        ?: (session('vin') ? 'VIN '.session('vin') : '');
    $выезд = App\Models\Service::visitFee();
    $выбранныйСпособ = old(
        'fulfillment',
        $карточка?->preferred_fulfillment ?? App\Enums\FulfillmentType::Installation->value
    );
    $выбраннаяОплата = old('payment_method', settings()->enabled('payments') ? App\Enums\PaymentMethod::Card->value : App\Enums\PaymentMethod::Cash->value);
    $широкая = $layout === 'wide';
    $id = uniqid();
@endphp

<form
    @if ($formId) id="{{ $formId }}" @endif
    method="post"
    action="{{ $action }}"
    class="space-y-6"
    data-order-form
    {{ $attributes }}
>
    @csrf
    <input type="hidden" name="form_token" value="{{ (string) Illuminate\Support\Str::uuid() }}">

    @if ($errors->any())
        <div tabindex="-1" role="alert" data-order-errors class="rounded-2xl border border-warn/40 bg-warn-soft p-3.5 text-sm text-warn-ink">
            <p class="font-bold">Заявку не отправили — проверьте поля ниже</p>
            {{-- Каждая ошибка ведёт к своему полю: на телефоне до него иначе листать (GOV.UK, error summary). --}}
            @php $поля = ['name' => "of-name-{$id}", 'phone' => "of-phone-{$id}", 'comment' => "of-comment-{$id}", 'car_info' => "of-car-{$id}"]; @endphp
            <ul class="mt-1.5 list-disc pl-4">
                @foreach ($errors->getMessages() as $поле => $messages)
                    @foreach ($messages as $message)
                        <li>
                            @isset($поля[$поле])
                                <a href="#{{ $поля[$поле] }}" class="font-semibold underline underline-offset-2">{{ $message }}</a>
                            @else
                                {{ $message }}
                            @endisset
                        </li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    <div class="hidden" aria-hidden="true">
        <label>Не заполняйте это поле
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>
    </div>

    <div @class([
        'space-y-6' => ! $широкая,
        'grid gap-6 lg:grid-cols-2 lg:gap-8' => $широкая,
    ])>
        <div class="space-y-6">
            <section class="space-y-3">
                <h2 class="text-sm font-extrabold tracking-tight">Контакты</h2>
                @php
                    /*
                     * Вошедшему поля уже заполнены — прячем их под свёртку
                     * «Имя · телефон — Изменить». Поля внутри закрытого details
                     * отправляются как обычно, так что заявка уходит в одно
                     * нажатие. Ошибка валидации или old() — свёртка открыта.
                     */
                    $контактыСвернуты = $вошёл !== null
                        && ! $errors->has('name') && ! $errors->has('phone')
                        && ! old('name') && ! old('phone');
                @endphp
                @if ($контактыСвернуты)
                    <details class="group" data-contacts-collapsed>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-2xl border border-line bg-surface px-4 py-3 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0 truncate text-sm">
                                <strong>{{ $вошёл->name }}</strong>
                                <span class="text-muted"> · {{ телефонДляГлаз($вошёл->phone) }}</span>
                            </span>
                            <span class="shrink-0 text-xs font-bold text-brand-ink">Изменить</span>
                        </summary>
                @elseif ($вошёл)
                    <p class="text-xs text-muted">
                        Данные из кабинета.
                        <a href="{{ route('account.profile') }}" class="font-semibold text-brand-ink underline-offset-2 hover:underline">Изменить</a>
                    </p>
                @endif
                <div @class(['grid gap-3 sm:grid-cols-2', 'mt-3' => $контактыСвернуты])>
                    <div>
                        <label for="of-name-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">
                            Как вас зовут
                        </label>
                        <input id="of-name-{{ $id }}" type="text" name="name" required maxlength="120"
                               value="{{ old('name', $вошёл?->name) }}" autocomplete="name" placeholder="Имя"
                               class="input @error('name') input-error @enderror">
                        @error('name')
                            <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="of-phone-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">Телефон</label>
                        <input id="of-phone-{{ $id }}" type="tel" name="phone" required maxlength="32"
                               value="{{ old('phone', $вошёл?->phone) }}" autocomplete="tel" placeholder="+7 (700) 000-00-00"
                               inputmode="tel"
                               class="input @error('phone') input-error @enderror">
                        @error('phone')
                            <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                @if ($контактыСвернуты)
                    </details>
                @endif
                @if ($карточка?->seesWholesalePrices() || $карточка?->company_name)
                    <p class="rounded-2xl bg-brand-soft px-3 py-2 text-xs font-semibold text-brand-ink">
                        {{ $карточка->seesWholesalePrices() ? 'Оптовый заказ' : 'Организация' }}
                        @if ($карточка->company_name)
                            · {{ $карточка->company_name }}
                        @endif
                        @if ($карточка->bin)
                            · БИН {{ $карточка->bin }}
                        @endif
                    </p>
                @endif
            </section>

            @if ($withCar)
                <div>
                    <label for="of-car-{{ $id }}" class="mb-1.5 block text-xs font-bold text-muted">
                        Автомобиль
                    </label>
                    <input id="of-car-{{ $id }}" type="text" name="car_info" maxlength="200"
                           value="{{ old('car_info', $машинаПоУмолчанию) }}"
                           placeholder="Марка, модель, год — например Toyota Camry 2018"
                           class="input">
                    <p class="mt-1 text-xs text-muted">
                        @if ($карточка?->vin || session('vin'))
                            VIN подставлен — можно дописать модель
                        @else
                            Знаете VIN — напишите его, подберём точнее
                        @endif
                    </p>
                </div>
            @endif

            @unless ($широкая)
                @include('components.partials.order-fulfillment', [
                    'id' => $id,
                    'выбранныйСпособ' => $выбранныйСпособ,
                    'выбраннаяОплата' => $выбраннаяОплата,
                    'выезд' => $выезд,
                    'адресПоУмолчанию' => $адресПоУмолчанию,
                    'withFulfillment' => $withFulfillment,
                ])
            @endunless

            @unless ($широкая)
                <details class="group" @if (old('comment')) open @endif>
                    <summary class="cursor-pointer text-xs font-bold text-muted">
                        Комментарий, если нужно
                    </summary>
                    <textarea id="of-comment-{{ $id }}" name="comment" rows="3" maxlength="2000"
                              placeholder="Что случилось со стеклом, удобное время, подъезд"
                              class="input mt-2">{{ old('comment') }}</textarea>
                </details>

                @if (settings()->enabled('privacy_consent'))
                    <label class="flex cursor-pointer items-start gap-2.5 text-sm leading-relaxed text-muted">
                        <input type="checkbox" name="privacy_consent" value="1" required
                               @checked(old('privacy_consent'))
                               class="mt-0.5 accent-brand @error('privacy_consent') outline outline-warn @enderror">
                        <span>
                            Соглашаюсь на сбор и обработку персональных данных
                            в соответствии с
                            <a href="{{ route('privacy') }}" target="_blank" rel="noopener"
                               class="font-bold text-brand-ink underline-offset-2 hover:underline">
                                политикой
                            </a>
                            и Законом РК «О персональных данных и их защите».
                        </span>
                    </label>
                    @error('privacy_consent')
                        <p class="text-xs text-warn-ink">{{ $message }}</p>
                    @enderror
                @endif
            @endunless
        </div>

        @if ($широкая)
            <div class="space-y-6">
                @include('components.partials.order-fulfillment', [
                    'id' => $id,
                    'выбранныйСпособ' => $выбранныйСпособ,
                    'выбраннаяОплата' => $выбраннаяОплата,
                    'выезд' => $выезд,
                    'адресПоУмолчанию' => $адресПоУмолчанию,
                    'withFulfillment' => $withFulfillment,
                ])
            </div>
        @endif
    </div>

    @if ($широкая)
        <div class="space-y-4">
            <details class="group" @if (old('comment')) open @endif>
                <summary class="cursor-pointer text-xs font-bold text-muted">
                    Комментарий, если нужно
                </summary>
                <textarea id="of-comment-{{ $id }}" name="comment" rows="3" maxlength="2000"
                          placeholder="Что случилось со стеклом, удобное время, подъезд"
                          class="input mt-2">{{ old('comment') }}</textarea>
            </details>

            @if (settings()->enabled('privacy_consent'))
                <label class="flex cursor-pointer items-start gap-2.5 text-sm leading-relaxed text-muted">
                    <input type="checkbox" name="privacy_consent" value="1" required
                           @checked(old('privacy_consent'))
                           class="mt-0.5 accent-brand @error('privacy_consent') outline outline-warn @enderror">
                    <span>
                        Соглашаюсь на сбор и обработку персональных данных
                        в соответствии с
                        <a href="{{ route('privacy') }}" target="_blank" rel="noopener"
                           class="font-bold text-brand-ink underline-offset-2 hover:underline">
                            политикой
                        </a>
                        и Законом РК «О персональных данных и их защите».
                    </span>
                </label>
                @error('privacy_consent')
                    <p class="text-xs text-warn-ink">{{ $message }}</p>
                @enderror
            @endif
        </div>
    @endif

    @if ($showSubmit)
        <button type="submit" class="btn btn-primary btn-lg w-full !rounded-2xl whitespace-normal">
            {{ $submit }}
        </button>

        <p class="text-center text-xs leading-relaxed text-muted">{{ $note }}</p>
    @endif
</form>
