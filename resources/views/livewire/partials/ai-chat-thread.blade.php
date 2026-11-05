<div class="relative flex min-h-0 flex-1 flex-col" @if ($activeStatus) wire:poll.1s.visible="refreshChat" @endif>
    @if (! $identityUnavailable)
        <div class="flex items-center gap-2 border-b border-line px-4 py-2" x-data="{ history: false }" x-on:keydown.escape.window="history = false">
            <button type="button" x-on:click="history = !history" x-bind:aria-expanded="history" class="btn !min-h-11 !px-3 !text-sm">{{ $this->say('История чатов', 'Чат тарихы') }}</button>
            <button type="button" wire:click="newConversation" wire:loading.attr="disabled" class="btn !min-h-11 !px-3 !text-sm">{{ $this->say('Новый чат', 'Жаңа чат') }}</button>
            <div x-show="history" x-cloak class="absolute left-2 right-2 top-14 z-10 max-h-64 overflow-y-auto rounded-xl border border-line bg-surface p-2 shadow-lift" aria-label="{{ $this->say('Сохранённые чаты', 'Сақталған чаттар') }}">
                @foreach ($conversations as $conversation)
                    <div wire:key="ai-conversation-{{ $conversation['id'] }}" class="flex items-center gap-2 rounded-lg {{ $conversationId === $conversation['id'] ? 'bg-sunken' : '' }}">
                        <button type="button" wire:click="selectConversation('{{ $conversation['id'] }}')" x-on:click="history = false" class="min-h-11 min-w-0 flex-1 px-3 py-2 text-left text-sm" @if ($conversationId === $conversation['id']) aria-current="true" @endif>
                            <span class="block truncate font-bold">{{ $conversation['title'] }}</span>
                            <span class="text-xs text-muted">{{ $conversation['date'] }}</span>
                        </button>
                        <button type="button" wire:click="deleteConversation('{{ $conversation['id'] }}')" wire:confirm="{{ $this->say('Удалить этот чат и его сообщения?', 'Бұл чатты және хабарламаларын жою керек пе?') }}" wire:loading.attr="disabled" class="min-h-11 px-3 text-sm text-muted" aria-label="{{ $this->say('Удалить чат', 'Чатты жою') }}: {{ $conversation['title'] }}">{{ $this->say('Удалить', 'Жою') }}</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    <div class="flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-ai-chat-scroll role="log" aria-label="{{ $this->say('Диалог с помощником', 'Көмекшімен диалог') }}" aria-relevant="additions text">
        @include('livewire.partials.assistant-journey')
        @foreach ($messages as $message)
            @if ($message['text'] !== '')
                <div wire:key="ai-message-{{ $message['id'] }}" @class([
                    'ml-auto max-w-[90%] rounded-2xl rounded-br-md bg-sunken px-4 py-3 text-base leading-relaxed sm:max-w-[80%]' => $message['role'] === 'user',
                    'mr-4 max-w-3xl text-base leading-relaxed sm:mr-12' => $message['role'] !== 'user',
                ])>
                    <span @class(['sr-only' => $message['role'] === 'user', 'mb-1 block text-xs font-bold text-muted' => $message['role'] !== 'user'])>{{ $message['role'] === 'user' ? $this->say('Вы: ', 'Сіз: ') : $this->say('Помощник', 'Көмекші') }}</span>
                    {!! nl2br(e($message['text'])) !!}
                </div>
            @endif
        @endforeach

        @if ($offers !== [])
            <div class="space-y-3" x-data="{ all: false }">
                <div class="grid gap-3 sm:grid-cols-2" role="list" aria-label="{{ $this->say('Найденные предложения', 'Табылған ұсыныстар') }}">
                    @foreach ($offers as $offer)
                        <article @if ($loop->index >= 3) x-show="all" x-cloak @endif wire:key="ai-offer-{{ $offer['offer_id'] }}" class="flex min-w-0 flex-col rounded-xl border border-line bg-sunken p-4" role="listitem">
                            <p class="font-bold">{{ $offer['name'] ?? $offer['article'] ?? ('offer_id '.$offer['offer_id']) }}</p>
                            @if (($offer['manufacturer'] ?? '') !== '')
                                <p class="mt-1 text-sm text-muted">{{ $offer['manufacturer'] }}@if ($offer['article'] ?? null) · {{ $offer['article'] }}@endif</p>
                            @elseif ($offer['article'] ?? null)
                                <p class="mt-1 text-sm text-muted">{{ $offer['article'] }}</p>
                            @endif
                            <p class="mt-2 font-bold">
                                {{ $this->money($offer['unit_price'] ?? null) }}
                                @if (($offer['requested_qty'] ?? 1) > 1)
                                    <span class="text-sm font-normal text-muted">× {{ $offer['requested_qty'] }} {{ $this->say('шт.', 'дана') }}</span>
                                @endif
                            </p>
                            @if (! empty($offer['options']))
                                <p class="mt-2 text-sm text-muted">{{ implode(', ', array_map(fn ($flag) => \App\Ai\AssistantSearchReply::optionLabel($flag, app()->getLocale() === 'kk'), array_slice(array_keys(array_filter($offer['options'], fn ($value) => $value === true)), 0, 3))) }}</p>
                            @endif
                            <p class="mt-1 text-sm text-muted">
                                {{ \App\Domain\Catalog\Availability::fromArray($offer['availability'] ?? ['state' => (($offer['available_qty'] ?? 0) > 0) ? 'in_stock' : 'unknown'])->label(app()->getLocale() === 'kk') }}
                                ·
                                {{ ($offer['fitment']['status'] ?? '') === 'matched'
                                    ? $this->say('Подходит', 'Сәйкес келеді')
                                    : $this->say('Проверит менеджер', 'Менеджер тексереді') }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="prepareOffer({{ $offer['offer_id'] }})" x-on:click="$el.closest('[data-ai-chat]')?.querySelector('textarea')?.focus()" class="btn btn-primary min-h-11 flex-1">
                                    {{ $this->say('Выбрать', 'Таңдау') }}
                                </button>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if (count($offers) > 3)
                    <button type="button" x-on:click="all = !all" x-bind:aria-expanded="all" class="btn w-full">
                        {{ $this->say('Ещё варианты', 'Басқа нұсқалар') }}
                    </button>
                @endif
            </div>
        @elseif ($canRequestManagerSelection && ! $activeStatus)
            <div class="rounded-xl border border-line bg-sunken p-4">
                <p class="text-sm text-muted">{{ $this->say('Точное совпадение в каталоге не найдено. Вы можете оставить заявку менеджеру на ручной подбор по VIN или параметрам авто.', 'Каталогтан нақты сәйкестік табылмады. VIN немесе көлік параметрлері бойынша менеджерге өтінім қалдыра аласыз.') }}</p>
                <button type="button" wire:click="prepareUnresolvedRequest" wire:loading.attr="disabled" class="btn btn-primary mt-3">
                    {{ $this->say('Оставить заявку менеджеру на подбор', 'Менеджерге таңдауға өтінім қалдыру') }}
                </button>
            </div>
        @endif

        @if ($orderCards !== [] && ! $activeStatus)
            <ul class="space-y-2" aria-label="{{ $this->say('Ваши заказы', 'Сіздің тапсырыстарыңыз') }}">
                @foreach ($orderCards as $card)
                    <li wire:key="ai-order-{{ $card['id'] }}" @class([
                        'rounded-xl border bg-sunken p-4',
                        'border-brand/50' => in_array($card['focus'], ['cancel_order', 'pay_order'], true),
                        'border-line' => ! in_array($card['focus'], ['cancel_order', 'pay_order'], true),
                    ])>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <a href="{{ $card['url'] }}" class="font-display font-extrabold underline-offset-4 hover:underline">{{ $card['number'] }}</a>
                            <span class="flex items-center gap-1.5">
                                @if ($card['paid'])<span class="badge badge-ok">{{ $this->say('Оплачен', 'Төленген') }}</span>@endif
                                <span class="badge badge-muted">{{ $card['status_label'] }}</span>
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-muted">
                            {{ $card['date'] }}@if ($card['first_item']) · {{ $card['first_item'] }}@endif
                            · <span class="font-bold text-ink">{{ $this->money($card['total']) }}</span>
                        </p>
                        @if ($cancelling === $card['id'])
                            <form wire:submit="confirmCancel" class="mt-3 space-y-2 border-t border-line pt-3">
                                <label class="block text-sm">{{ $this->say('Причина — необязательно, поможет нам', 'Себебі — міндетті емес') }}
                                    <input class="input mt-1 !py-2 !text-sm" wire:model="cancelReason" maxlength="500" placeholder="{{ $this->say('Нашёл дешевле, передумал, продал машину…', 'Арзанырақ таптым, ойымды өзгерттім…') }}">
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-primary !min-h-10 !px-3 !text-sm" wire:loading.attr="disabled">{{ $this->say('Да, отменить заказ', 'Иә, бас тарту') }}</button>
                                    <button type="button" class="btn !min-h-10 !px-3 !text-sm" wire:click="$set('cancelling', '')">{{ $this->say('Не отменять', 'Бас тартпау') }}</button>
                                </div>
                            </form>
                        @else
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if ($card['can_pay'])
                                    <button type="button" wire:click="payOrder('{{ $card['id'] }}')" wire:loading.attr="disabled" class="btn btn-primary !min-h-10 !px-3 !text-sm">{{ $this->say('Оплатить', 'Төлеу') }} {{ $this->money($card['total']) }}</button>
                                @endif
                                <button type="button" wire:click="repeatOrder('{{ $card['id'] }}')" wire:loading.attr="disabled" class="btn !min-h-10 !px-3 !text-sm">{{ $this->say('Заказать снова', 'Қайта тапсырыс беру') }}</button>
                                @if ($card['can_cancel'])
                                    <button type="button" wire:click="startCancel('{{ $card['id'] }}')" class="btn !min-h-10 !px-3 !text-sm">{{ $this->say('Отменить заказ', 'Бас тарту') }}</button>
                                @endif
                                <a href="{{ $card['url'] }}" class="btn btn-ghost !min-h-10 !px-3 !text-sm">{{ $this->say('Подробнее', 'Толығырақ') }}</a>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @foreach ($checkouts as $checkout)
            <section wire:key="ai-checkout-{{ $checkout['id'] }}-{{ $checkout['quote_id'] }}" class="space-y-3 rounded-xl border border-line bg-sunken p-4" aria-label="{{ $this->say('Подготовленная заявка', 'Дайындалған өтінім') }}">
                @if ($checkout['status'] === 'submitted')
                    <p class="font-bold">{{ $this->say('Заявка принята. Номер: ', 'Өтінім қабылданды. Нөмірі: ') }}{{ $checkout['result']['order_number'] ?? '' }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        @if (! empty($checkout['result']['id']))
                            <a href="{{ route('order.track', ['order' => $checkout['result']['id']]) }}" class="btn !min-h-10 !px-3 !text-sm">
                                {{ $this->say('Статус заказа', 'Тапсырыс мәртебесі') }}
                            </a>
                            @if (($checkout['current_order']['can_pay'] ?? false) && in_array($checkout['current_order']['payment_method'] ?? '', ['card', 'kaspi'], true))
                                    <button type="button" wire:click="payCheckout('{{ $checkout['id'] }}')" class="btn btn-primary !min-h-10 !px-3 !text-sm">
                                        {{ $this->say('Оплатить онлайн', 'Онлайн төлеу') }}
                                    </button>
                            @endif
                            <a class="btn !text-sm" href="{{ route('purchase.show', $checkout['result']['id']) }}">{{ $this->say('Заказ, оплата и документы', 'Тапсырыс, төлем және құжаттар') }}</a>
                        @endif
                        @if ($checkout['result']['order_number'] ?? null)
                            <a href="{{ route('orders.lookup') }}" class="text-sm font-bold text-brand-ink underline-offset-4 hover:underline">
                                {{ $this->say('Найти заявку по номеру', 'Өтінімді нөмірі бойынша табу') }}
                            </a>
                        @endif
                    </div>
                @else
                    <p class="font-bold">{{ $this->say('Проверьте заявку', 'Өтінімді тексеріңіз') }}</p>
                    <p class="text-sm">{{ $checkout['contacts']['name'] ?? '' }} · {{ $checkout['contacts']['phone'] ?? '' }}</p>
                    @if ($checkout['vehicle_label'] ?? null)
                        <p class="text-sm">{{ $this->say('Автомобиль: ', 'Көлік: ') }}{{ $checkout['vehicle_label'] }}</p>
                    @endif
                    @if ($checkout['vehicle']['year'] ?? null)
                        <p class="text-sm">{{ $this->say('Год автомобиля: ', 'Көлік жылы: ') }}{{ $checkout['vehicle']['year'] }}</p>
                    @endif
                    @if ($checkout['vehicle']['vin'] ?? null)
                        <p class="text-sm">VIN: {{ $checkout['vehicle']['vin'] }}</p>
                    @endif
                    @foreach ($checkout['items'] ?? [] as $item)
                        <p class="text-sm">{{ $item['name'] ?? $item['article'] ?? $item['reference'] ?? '' }} · {{ $item['manufacturer'] ?? '' }} · {{ $item['quantity'] }} {{ $this->say('шт.', 'дана') }} · {{ $this->money($item['total'] ?? null) }}</p>
                        @if ($item['comment'] ?? null)
                            <p class="text-sm text-muted">{{ $item['comment'] }}</p>
                        @endif
                    @endforeach
                    <p class="text-sm">{{ $this->say('Город: ', 'Қала: ') }}{{ $checkout['fulfillment']['city'] ?? '' }}</p>
                    <p class="text-sm">{{ match ($checkout['fulfillment']['type'] ?? '') {
                        'pickup' => $this->say('Самовывоз', 'Өзі алып кету'),
                        'delivery' => $this->say('Доставка', 'Жеткізу'),
                        'installation' => $this->say('Установка в сервисе', 'Сервисте орнату'),
                        'visit' => $this->say('Выездная установка', 'Көшпелі орнату'),
                        default => $this->say('Способ получения уточнит менеджер', 'Алу тәсілін менеджер нақтылайды'),
                    } }}</p>
                    @if ($checkout['fulfillment']['point'] ?? null)
                        <p class="text-sm">{{ $checkout['fulfillment']['point']['name'] }} · {{ $checkout['fulfillment']['point']['address'] }}</p>
                    @endif
                    @if ($checkout['fulfillment']['address'] ?? null)
                        <p class="text-sm">{{ $checkout['fulfillment']['address'] }}</p>
                    @endif
                    @if (($checkout['fulfillment']['type'] ?? '') === 'delivery')
                        <p class="text-sm">{{ $this->say('Стоимость доставки: ', 'Жеткізу құны: ') }}{{ $this->money($checkout['delivery_total'] ?? null) }}</p>
                    @endif
                    @if ($checkout['fulfillment']['slot_start'] ?? null)
                        <p class="text-sm">{{ \Carbon\CarbonImmutable::parse($checkout['fulfillment']['slot_start'])->setTimezone($checkout['fulfillment']['point']['timezone'] ?? config('app.timezone'))->format('d.m.Y H:i T') }}</p>
                    @endif
                    <p class="font-bold">{{ $this->say('Итого: ', 'Барлығы: ') }}{{ $this->money($checkout['total'] ?? null) }}</p>
                    <p class="text-sm">{{ $this->say('Оплата: ', 'Төлем: ') }}{{ $checkout['payment_methods'][$checkout['payment_method'] ?? 'cash'] ?? $this->say('Уточните способ оплаты', 'Төлем тәсілін нақтылаңыз') }}</p>
                    @if (($checkout['fulfillment']['type'] ?? '') === 'installation')
                        <div class="space-y-2 rounded-lg border border-line p-3">
                            <label class="block text-sm">{{ $this->say('Сервис', 'Сервис') }}<select class="input mt-1" wire:model="checkoutEdits.{{ $checkout['id'] }}.service_point_id"><option value="">{{ $this->say('Выберите пункт', 'Орынды таңдаңыз') }}</option>@foreach ($checkout['service_points'] ?? [] as $point)<option value="{{ $point['id'] }}">{{ $point['name'] }} · {{ $point['address'] }}</option>@endforeach</select></label>
                            <label class="block text-sm">{{ $this->say('Дата установки', 'Орнату күні') }}<input type="date" class="input mt-1" wire:model="slotDate"></label>
                            <button type="button" class="btn !text-sm" wire:click="loadCheckoutSlots('{{ $checkout['id'] }}')">{{ $this->say('Показать свободное время', 'Бос уақытты көрсету') }}</button>
                            @foreach ($checkoutSlots[$checkout['id']] ?? [] as $i => $slot)<button type="button" class="btn !text-sm" wire:click="chooseCheckoutSlot('{{ $checkout['id'] }}', {{ $i }})">{{ $slot['label'] }}</button>@endforeach
                        </div>
                    @endif
                    @if (($checkout['total'] ?? null) === null)
                        <p class="text-sm">{{ $this->say('Известная часть стоимости: ', 'Белгілі құны: ') }}{{ $this->money($checkout['known_total'] ?? null) }}</p>
                    @endif
                    @if (($checkout['reason_labels'] ?? []) !== [])
                        @foreach ($checkout['reason_labels'] as $reason)
                            <p class="text-sm text-muted">{{ $reason }}</p>
                        @endforeach
                    @elseif (($checkout['readiness'] ?? '') !== 'ready')
                        <p class="text-sm text-muted">{{ $this->say('Совместимость, наличие или итоговую стоимость должен уточнить менеджер.', 'Үйлесімділікті, қорды немесе соңғы құнын менеджер нақтылауы керек.') }}</p>
                    @endif
                    <details class="rounded-lg border border-line bg-surface p-3 text-sm">
                        <summary class="cursor-pointer font-bold">{{ $this->say('Контакты, получение и оплата', 'Байланыс, алу және төлем') }}</summary>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label class="block"><span class="mb-1 block text-xs text-muted">{{ $this->say('Оплата', 'Төлем') }}</span><select wire:model="checkoutEdits.{{ $checkout['id'] }}.payment_method" class="input !py-2 !text-sm">@foreach ($checkout['payment_methods'] ?? ['cash' => $this->say('При получении', 'Алған кезде')] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-muted">{{ $this->say('Имя', 'Аты') }}</span>
                                <input type="text" wire:model="checkoutEdits.{{ $checkout['id'] }}.name" class="input !py-2 !text-sm">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-muted">{{ $this->say('Телефон', 'Телефон') }}</span>
                                <input type="text" wire:model="checkoutEdits.{{ $checkout['id'] }}.phone" class="input !py-2 !text-sm">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-muted">{{ $this->say('Способ получения', 'Алу тәсілі') }}</span>
                                <select wire:model="checkoutEdits.{{ $checkout['id'] }}.fulfillment" class="input !py-2 !text-sm">
                                    <option value="pickup">{{ $this->say('Самовывоз', 'Өзі алып кету') }}</option>
                                    <option value="delivery">{{ $this->say('Доставка', 'Жеткізу') }}</option>
                                    <option value="installation">{{ $this->say('Установка в сервисе', 'Сервисте орнату') }}</option>
                                    <option value="visit">{{ $this->say('Выездная установка', 'Көшпелі орнату') }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-muted">{{ $this->say('Город', 'Қала') }}</span>
                                <input type="text" wire:model="checkoutEdits.{{ $checkout['id'] }}.city" class="input !py-2 !text-sm">
                            </label>
                            <label class="block"><span class="mb-1 block text-xs text-muted">{{ $this->say('Пункт получения', 'Алу орны') }}</span><select class="input !py-2 !text-sm" wire:model="checkoutEdits.{{ $checkout['id'] }}.service_point_id"><option value="">{{ $this->say('Выберите пункт', 'Орынды таңдаңыз') }}</option>@foreach ($checkout['service_points'] ?? [] as $point)<option value="{{ $point['id'] }}">{{ $point['name'] }}</option>@endforeach</select></label>
                            <label class="block sm:col-span-2">
                                <span class="mb-1 block text-xs text-muted">{{ $this->say('Адрес (для доставки/выезда)', 'Мекенжай (жеткізу/шығу үшін)') }}</span>
                                <input type="text" wire:model="checkoutEdits.{{ $checkout['id'] }}.address" class="input !py-2 !text-sm">
                            </label>
                        </div>
                        <button type="button" wire:click="updateCheckoutDetails('{{ $checkout['id'] }}')" wire:loading.attr="disabled" class="btn mt-3 !min-h-10 !px-3 !text-sm">
                            {{ $this->say('Сохранить и пересчитать', 'Сақтау және қайта есептеу') }}
                        </button>
                    </details>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" wire:model="privacyConsent.{{ $checkout['id'] }}" class="mt-1">
                        <span>{{ $this->say('Согласен на обработку персональных данных для оформления заявки.', 'Өтінім беру үшін жеке деректерімді өңдеуге келісемін.') }}</span>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="confirmCheckout('{{ $checkout['id'] }}')" wire:loading.attr="disabled" @disabled(($checkout['readiness'] ?? '') === 'incomplete' || ($checkout['stale'] ?? false)) class="btn btn-primary">{{ in_array($checkout['action'] ?? '', ['confirm_retail', 'confirm_wholesale'], true) ? $this->say('Подтвердить заказ', 'Тапсырысты растау') : $this->say('Отправить заявку менеджеру', 'Өтінімді менеджерге жіберу') }}</button>
                        <button type="button" wire:click="refreshCheckout('{{ $checkout['id'] }}')" wire:loading.attr="disabled" class="btn">{{ $this->say('Обновить расчёт', 'Есепті жаңарту') }}</button>
                    </div>
                    @if ($checkout['stale'] ?? false)<p class="text-sm font-bold">{{ $this->say('Подбор изменён. Обновите расчёт или выберите товар заново.', 'Таңдау өзгерді. Есепті жаңартыңыз немесе тауарды қайта таңдаңыз.') }}</p>@endif
                @endif
            </section>
        @endforeach

        @if ($activeStatus)
            <div class="text-sm text-muted" role="status">
                {{ $activeStatus === 'queued' ? $this->say('Запрос принят. Ожидаем помощника…', 'Сұрау қабылданды. Көмекшіні күтеміз…') : $this->say('Помощник отвечает…', 'Көмекші жауап беруде…') }}
                <button type="button" wire:click="cancel" class="ml-2 underline">{{ $this->say('Остановить', 'Тоқтату') }}</button>
            </div>
        @endif
        @if ($notice !== '')
            <p class="text-sm text-muted" role="status">{{ $notice }}</p>
        @endif
        @if ($canRetry && ! $activeStatus)
            <button type="button" wire:click="retry" wire:loading.attr="disabled" class="btn">{{ $this->say('Повторить ответ', 'Жауапты қайталау') }}</button>
        @endif
    </div>

    <div class="flex items-end gap-2 border-t border-line p-3 sm:p-4">
        <textarea wire:model="draft" maxlength="1000" autocomplete="off" rows="2"
               disabled x-bind:disabled="$wire.activeStatus !== null || $wire.identityUnavailable"
               aria-label="{{ $this->say('Сообщение помощнику', 'Көмекшіге хабарлама') }}" enterkeyhint="send" inputmode="text"
               placeholder="{{ $this->say('Camry 2018, лобовое…', 'Camry 2018, алдыңғы әйнек…') }}"
               class="input resize-none !rounded-xl !py-3 !text-base"
               x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $refs.sendMessage.click(); }"
               wire:loading.attr="disabled" wire:target="send"></textarea>
        <button type="button" wire:click="send" x-ref="sendMessage" class="btn btn-primary !min-h-11 !min-w-11 !rounded-xl !p-3"
                disabled x-bind:disabled="$wire.activeStatus !== null || $wire.identityUnavailable"
                wire:loading.attr="disabled" wire:target="send"
                aria-label="{{ $this->say('Отправить', 'Жіберу') }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0-5.25-5.25M19.5 12l-5.25 5.25"/>
            </svg>
        </button>
    </div>
    <noscript><p class="px-4 pb-4 text-sm text-muted">{{ $this->say('Для диалога включите JavaScript или подберите стекло в каталоге.', 'Диалог үшін JavaScript қосыңыз немесе каталогтан әйнек таңдаңыз.') }} <a href="{{ route('catalog.brands') }}" class="underline">{{ $this->say('Каталог', 'Каталог') }}</a></p></noscript>
</div>
