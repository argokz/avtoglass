@props([
    'action',
    'title' => 'Оставить заявку',
    'submit' => 'Отправить заявку',
    'note' => 'Менеджер перезвонит, подтвердит наличие и назовёт срок',
    'withCar' => false,
    'withFulfillment' => true,
])

<form method="post" action="{{ $action }}" class="space-y-3" data-order-form>
    @csrf

    {{-- Ловушка для ботов: поле спрятано от людей и от скринридеров,
         но автозаполнялки роботов его находят и выдают себя. --}}
    <div class="hidden" aria-hidden="true">
        <label>Не заполняйте это поле
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <label for="of-name-{{ $id = uniqid() }}" class="mb-1 block text-xs font-semibold text-muted">
                Как вас зовут
            </label>
            <input id="of-name-{{ $id }}" type="text" name="name" required maxlength="120"
                   value="{{ old('name') }}" autocomplete="name" placeholder="Имя"
                   class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                          placeholder:text-faint focus:border-brand focus:outline-none
                          @error('name') border-warn @enderror">
            @error('name')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="of-phone-{{ $id }}" class="mb-1 block text-xs font-semibold text-muted">Телефон</label>
            <input id="of-phone-{{ $id }}" type="tel" name="phone" required maxlength="32"
                   value="{{ old('phone') }}" autocomplete="tel" placeholder="+7 (700) 000-00-00"
                   inputmode="tel"
                   class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                          placeholder:text-faint focus:border-brand focus:outline-none
                          @error('phone') border-warn @enderror">
            @error('phone')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>
    </div>

    @if ($withCar)
        <div>
            <label for="of-car-{{ $id }}" class="mb-1 block text-xs font-semibold text-muted">
                Автомобиль
            </label>
            <input id="of-car-{{ $id }}" type="text" name="car_info" maxlength="200"
                   value="{{ old('car_info') }}" placeholder="Марка, модель, год — например Toyota Camry 2018"
                   class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                          placeholder:text-faint focus:border-brand focus:outline-none">
            <p class="mt-1 text-xs text-faint">Знаете VIN — напишите его, подберём точнее</p>
        </div>
    @endif

    @if ($withFulfillment)
        <fieldset>
            <legend class="mb-1.5 block text-xs font-semibold text-muted">Что вам удобнее</legend>
            <div class="grid gap-2 sm:grid-cols-3" data-fulfillment>
                @foreach (App\Enums\FulfillmentType::cases() as $type)
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line
                                  bg-surface px-3 py-2.5 text-sm transition hover:border-brand/40
                                  has-checked:border-brand has-checked:bg-brand-soft">
                        <input type="radio" name="fulfillment" value="{{ $type->value }}"
                               @checked(old('fulfillment') === $type->value)
                               class="accent-brand">
                        {{ $type->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- Адрес показываем только для доставки: остальным он не нужен --}}
        <div data-address-field @class(['hidden' => old('fulfillment') !== 'delivery'])>
            <label for="of-addr-{{ $id }}" class="mb-1 block text-xs font-semibold text-muted">Адрес доставки</label>
            <input id="of-addr-{{ $id }}" type="text" name="address" maxlength="255" value="{{ old('address') }}"
                   placeholder="Город, улица, дом"
                   class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                          placeholder:text-faint focus:border-brand focus:outline-none
                          @error('address') border-warn @enderror">
            @error('address')
                <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p>
            @enderror
        </div>
    @endif

    <div>
        <label for="of-comment-{{ $id }}" class="mb-1 block text-xs font-semibold text-muted">
            Комментарий
        </label>
        <textarea id="of-comment-{{ $id }}" name="comment" rows="3" maxlength="2000"
                  placeholder="Что случилось со стеклом, когда удобно приехать"
                  class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                         placeholder:text-faint focus:border-brand focus:outline-none">{{ old('comment') }}</textarea>
    </div>

    <button type="submit"
            class="w-full rounded-xl bg-brand py-3.5 font-bold text-on-brand transition
                   hover:bg-brand-hover active:scale-[0.99]">
        {{ $submit }}
    </button>

    <p class="text-center text-xs text-faint">{{ $note }}</p>
</form>
