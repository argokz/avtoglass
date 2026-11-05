@if (! $identityUnavailable)
    <div class="space-y-3 border-b border-line bg-surface px-4 py-3">
        @if (auth()->check() && auth()->user()->customer_id)
            <a class="text-sm font-bold underline" href="{{ route('account.assistant-memory') }}">{{ $this->say('Что помнит помощник', 'Көмекші жады') }}</a>
        @endif
        @if ($rememberedVehicles !== [])
            <div class="flex flex-wrap gap-2" aria-label="{{ $this->say('Мои автомобили', 'Менің көліктерім') }}">
                @foreach ($rememberedVehicles as $vehicle)
                    <button type="button" class="btn !min-h-11 !px-3 !text-sm" wire:click="useRememberedVehicle('{{ $vehicle['id'] }}')">{{ $vehicle['label'] }} {{ $vehicle['year'] ?? '' }}</button>
                @endforeach
            </div>
        @endif
        @if (($shopping['awaiting_option'] ?? null) && ! $activeStatus)
            <div class="space-y-2">
                <p class="text-sm font-bold">{{ \App\Ai\AssistantSearchReply::optionLabel($shopping['awaiting_option'], app()->getLocale() === 'kk') }}?</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['yes' => $this->say('Есть', 'Бар'), 'no' => $this->say('Нет', 'Жоқ'), 'unknown' => $this->say('Не знаю', 'Білмеймін')] as $value => $label)
                        <button type="button" class="btn !min-h-11 !px-3 !text-sm" wire:click="answerOption('{{ $shopping['awaiting_option'] }}', '{{ $value }}')">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        @endif
        <details>
            <summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">{{ $this->say('Закупка списком или файлом', 'Тізіммен немесе файлмен сатып алу') }}</summary>
            <form wire:submit="reviewPurchaseList" class="mt-2 space-y-2">
                <label class="block text-sm">{{ $this->say('Артикул и количество, каждый товар с новой строки', 'Артикул және саны, әр тауар жаңа жолдан') }}<textarea class="input mt-1" rows="3" wire:model="purchaseList" maxlength="64000" placeholder="DEMO-1;3&#10;DEMO-2;2"></textarea></label>
                <button type="submit" class="btn !text-sm" wire:loading.attr="disabled">{{ $this->say('Проверить список', 'Тізімді тексеру') }}</button>
            </form>
            <form wire:submit="reviewPurchaseFile" class="mt-3 space-y-2">
                <label class="block text-sm">CSV / XLSX, {{ $this->say('до 20 МБ', '20 МБ дейін') }}<input class="mt-1 block max-w-full text-sm" type="file" wire:model="purchaseFile" accept=".csv,.xlsx"></label>
                <button class="btn !text-sm" type="submit" wire:loading.attr="disabled">{{ $this->say('Проверить файл', 'Файлды тексеру') }}</button>
            </form>
            @foreach ($errors->all() as $error)<p class="mt-2 text-sm text-red-600" role="alert">{{ $error }}</p>@endforeach
        </details>
        <button type="button" class="btn !text-sm" wire:click="requestManager">{{ $this->say('Обратиться к менеджеру', 'Менеджерге хабарласу') }}</button>
        @if (empty($shopping['selected']) && empty($shopping['lines']) && ! auth()->check())
            <details><summary class="cursor-pointer py-3 text-sm">{{ $this->say('Контакты для обращения', 'Сұрау үшін байланыс деректері') }}</summary><div class="grid gap-2 sm:grid-cols-2"><label class="text-sm">{{ $this->say('Имя', 'Аты') }}<input class="input mt-1" wire:model="contactDetails.name" maxlength="100"></label><label class="text-sm">{{ $this->say('Телефон', 'Телефон') }}<input class="input mt-1" type="tel" wire:model="contactDetails.phone" maxlength="32"></label></div></details>
        @endif
        @if (($shopping['lines'] ?? []) !== [])
            <div class="max-h-80 space-y-3 overflow-y-auto" aria-label="{{ $this->say('Спецификация закупки', 'Сатып алу тізімі') }}">
                @foreach ($shopping['lines'] as $line)
                    <div class="border-t border-line pt-3" wire:key="purchase-line-{{ $line['id'] ?? $line['reference'] }}">
                        <p class="text-sm font-bold">{{ $line['reference'] }} × {{ $line['quantity'] }}</p>
                        @if ($line['issue'] ?? null)<p class="text-sm text-red-600">{{ $this->say('Проверьте код и количество (опт 1–99). Исправьте исходный список и проверьте заново.', 'Код пен санын тексеріңіз (көтерме 1–99). Бастапқы тізімді түзетіп, қайта тексеріңіз.') }}</p>
                        @elseif (empty($line['candidates']))<p class="text-sm text-muted">{{ $this->say('Не найдено. Строка сохранена для менеджера.', 'Табылмады. Жол менеджер үшін сақталды.') }}</p>
                        @else
                            @foreach ($line['candidates'] as $candidate)
                                <button type="button" wire:loading.attr="disabled" wire:click="choosePurchaseLine('{{ $line['id'] }}', {{ $candidate['offer_id'] }})" class="btn mt-2 w-full !justify-start !text-left !text-sm {{ ($line['offer_id'] ?? null) === $candidate['offer_id'] ? 'btn-primary' : '' }}">
                                    {{ $candidate['article'] }} · {{ $candidate['manufacturer'] }} — {{ ($line['offer_id'] ?? null) === $candidate['offer_id'] ? $this->say('Выбрано', 'Таңдалды') : $this->say('Подтвердить артикул и производителя', 'Артикул мен өндірушіні растау') }}
                                </button>
                            @endforeach
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @if (($shopping['selected'] ?? []) !== [] || ($shopping['lines'] ?? []) !== [])
            <form wire:submit="prepareSelected" class="grid gap-2 sm:grid-cols-2">
                <label class="text-sm">{{ $this->say('Имя', 'Аты') }}<input class="input mt-1" wire:model="contactDetails.name" autocomplete="name" maxlength="100"></label>
                <label class="text-sm">{{ $this->say('Телефон', 'Телефон') }}<input class="input mt-1" type="tel" wire:model="contactDetails.phone" autocomplete="tel" maxlength="32"></label>
                <button class="btn btn-primary sm:col-span-2" type="submit" wire:loading.attr="disabled">{{ $this->say('Подготовить расчёт', 'Есепті дайындау') }}</button>
            </form>
        @endif
    </div>
@endif
