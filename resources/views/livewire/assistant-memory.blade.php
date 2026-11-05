<div class="mt-5 space-y-6">
    <div>
        <h1 class="text-2xl font-extrabold sm:text-3xl">{{ $this->say('Что помнит помощник', 'Көмекші нені есте сақтайды') }}</h1>
        <p class="mt-2 max-w-prose text-sm text-muted">{{ $this->say('Сохраните автомобиль и получение, чтобы не вводить их заново. Перед покупкой вы проверяете условия. Цены и наличие обновляются отдельно.', 'Көлікті және алу тәсілін қайта енгізбеу үшін сақтаңыз. Сатып алу алдында шарттарды тексересіз. Бағалар мен қор бөлек жаңартылады.') }}</p>
    </div>
    <form wire:submit="setConsent" class="space-y-3 border-y border-line py-5">
        <label class="flex items-start gap-3"><input type="checkbox" wire:model="consent" class="mt-1"><span>{{ $this->say('Разрешаю сохранять мои сведения для персонального подбора между обращениями.', 'Жеке таңдау үшін мәліметтерімді өтініштер арасында сақтауға келісемін.') }}</span></label>
        <button class="btn" type="submit">{{ $this->say('Сохранить согласие', 'Келісімді сақтау') }}</button>
    </form>
    @if ($consent)
        <form wire:submit="save" class="space-y-5">
            <h2 class="text-lg font-bold">{{ $this->say('Мои автомобили', 'Менің көліктерім') }}</h2>
            @foreach ($facts['vehicles'] ?? [] as $i => $vehicle)
                <div wire:key="memory-vehicle-{{ $vehicle['id'] }}" class="grid gap-3 border-b border-line pb-4 sm:grid-cols-2">
                    @php($vehicleFacts = $memoryFacts['vehicles']['subjects'][$vehicle['id']] ?? [])
                    <label>{{ $this->say('Автомобиль', 'Көлік') }}<input class="input mt-1" wire:model="facts.vehicles.{{ $i }}.label" maxlength="100" placeholder="Toyota Camry"></label>
                    <label>{{ $this->say('Год', 'Жыл') }}<input class="input mt-1" type="number" wire:model="facts.vehicles.{{ $i }}.year" min="1900" max="{{ now()->year + 2 }}"></label>
                    <details class="sm:col-span-2"><summary class="cursor-pointer py-2">{{ $this->say('Комплектация', 'Жабдықталуы') }}</summary><div class="grid gap-3 sm:grid-cols-2">
                        @foreach (\App\Models\Product::OPTION_FLAGS as $flag => $label)
                            <label>{{ \App\Ai\AssistantSearchReply::optionLabel($flag, app()->getLocale() === 'kk') }}<select class="input mt-1" wire:model="facts.vehicles.{{ $i }}.options.{{ $flag }}"><option value="">{{ $this->say('Не знаю', 'Білмеймін') }}</option><option value="1">{{ $this->say('Да', 'Иә') }}</option><option value="0">{{ $this->say('Нет', 'Жоқ') }}</option></select></label>
                            @if ($provenance = $vehicleFacts['options.'.$flag] ?? null)<p class="self-center text-xs text-muted">{{ ($provenance['source'] ?? '') === 'customer_form' ? $this->say('Из ваших настроек', 'Сіздің параметрлеріңізден') : $this->say('Из вашего сообщения', 'Сіздің хабарламаңыздан') }} · {{ \Carbon\CarbonImmutable::parse($provenance['confirmed_at'])->format('d.m.Y H:i') }}</p>@endif
                        @endforeach
                    </div></details>
                    <button type="button" wire:click="removeVehicle('{{ $vehicle['id'] }}')" class="min-h-11 text-left text-sm underline">{{ $this->say('Удалить автомобиль', 'Көлікті жою') }}</button>
                </div>
            @endforeach
            <button type="button" wire:click="addVehicle" class="btn">{{ $this->say('Добавить автомобиль', 'Көлік қосу') }}</button>
            <div class="grid gap-3 sm:grid-cols-2">
                <label>{{ $this->say('Город', 'Қала') }}<input class="input mt-1" wire:model="facts.city" maxlength="120"></label>
                <label>{{ $this->say('Получение', 'Алу тәсілі') }}<select class="input mt-1" wire:model="facts.fulfillment"><option value="">{{ $this->say('Уточнять при покупке', 'Сатып алғанда нақтылау') }}</option><option value="pickup">{{ $this->say('Самовывоз', 'Өзі алып кету') }}</option><option value="delivery">{{ $this->say('Доставка', 'Жеткізу') }}</option><option value="installation">{{ $this->say('Установка в сервисе', 'Сервисте орнату') }}</option></select></label>
                <label class="sm:col-span-2">{{ $this->say('Адрес доставки', 'Жеткізу мекенжайы') }}<input class="input mt-1" wire:model="facts.address" maxlength="500"></label>
            </div>
            @foreach ($errors->all() as $error)<p class="text-sm text-red-600" role="alert">{{ $error }}</p>@endforeach
            <button class="btn btn-primary" type="submit">{{ $this->say('Сохранить сведения', 'Мәліметтерді сақтау') }}</button>
            @foreach ($memoryFacts as $field => $fact)
                <p class="text-xs text-muted">{{ match ($field) {'vehicles' => $this->say('Автомобили', 'Көліктер'), 'city' => $this->say('Город', 'Қала'), 'address' => $this->say('Адрес', 'Мекенжай'), 'fulfillment' => $this->say('Получение', 'Алу тәсілі'), default => ''} }} · {{ ($fact['source'] ?? '') === 'customer_form' ? $this->say('Из ваших настроек', 'Сіздің параметрлеріңізден') : $this->say('Из вашего сообщения', 'Сіздің хабарламаңыздан') }} · {{ !empty($fact['confirmed_at']) ? \Carbon\CarbonImmutable::parse($fact['confirmed_at'])->format('d.m.Y H:i') : '' }} · {{ $this->say('Подтверждено вами', 'Сіз растадыңыз') }}</p>
            @endforeach
        </form>
        <button type="button" class="min-h-11 text-sm underline" wire:click="forget" wire:confirm="{{ $this->say('Удалить память и отключить персонализацию?', 'Жадты жойып, жекелендіруді өшіру керек пе?') }}">{{ $this->say('Удалить память и отключить', 'Жадты жою және өшіру') }}</button>
    @endif
    @if ($guestChats->isNotEmpty())
        <section class="space-y-3 border-t border-line pt-5"><h2 class="text-lg font-bold">{{ $this->say('Продолжить гостевой подбор', 'Қонақ таңдауын жалғастыру') }}</h2>
            @foreach ($guestChats as $chat)<button type="button" class="btn block w-full text-left" wire:click="importConversation('{{ $chat->id }}')">{{ $this->say('Перенести в кабинет: ', 'Кабинетке көшіру: ') }}{{ $chat->title ?: $this->say('Диалог', 'Диалог') }}</button>@endforeach
        </section>
    @endif
    @if ($notice)<p role="status" class="text-sm font-bold">{{ $notice }}</p>@endif
</div>
