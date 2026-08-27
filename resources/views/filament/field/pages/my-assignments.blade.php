<x-filament-panels::page>

    {{-- Переключатель дня: курьер смотрит сегодня, вечером — завтра --}}
    <div class="flex items-center justify-between gap-3 rounded-xl bg-white p-3 shadow-sm dark:bg-gray-900">
        <x-filament::button wire:click="previousDay" color="gray" icon="heroicon-o-chevron-left" size="lg">
            Назад
        </x-filament::button>

        <div class="text-center">
            <div class="text-lg font-bold">
                {{ $this->getDay()->translatedFormat('d MMMM') }}
            </div>
            <div class="text-sm text-gray-500">
                {{ $this->getDay()->translatedFormat('l') }} · нарядов: {{ $this->assignments->count() }}
            </div>
        </div>

        <x-filament::button wire:click="nextDay" color="gray" icon="heroicon-o-chevron-right" size="lg">
            Вперёд
        </x-filament::button>
    </div>

    {{-- Реестр печатают перед выездом: по нему получают стекло со склада
         и собирают подписи клиентов. --}}
    @if ($this->assignments->isNotEmpty())
        <div class="flex justify-end">
            <x-filament::button
                tag="a"
                href="{{ route('document.manifest', [auth()->user(), 'day' => $this->getDay()->toDateString()]) }}"
                target="_blank"
                color="gray"
                icon="heroicon-o-printer">
                Реестр на печать
            </x-filament::button>
        </div>
    @endif

    @forelse ($this->assignments as $assignment)
        @php
            $order = $assignment->order;
            $done = $assignment->status->isFinal();
        @endphp

        <div @class([
            'rounded-xl border p-4 shadow-sm',
            'bg-white dark:bg-gray-900',
            'border-gray-200 dark:border-gray-700' => ! $done,
            'border-gray-200 opacity-60 dark:border-gray-800' => $done,
        ])>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div class="text-2xl font-bold">{{ $assignment->scheduled_at->format('H:i') }}</div>
                    <div class="text-sm text-gray-500">
                        {{ $assignment->type->label() }} · {{ $assignment->duration_minutes }} мин
                    </div>
                </div>

                <x-filament::badge :color="$assignment->status->color()" size="lg">
                    {{ $assignment->status->label() }}
                </x-filament::badge>
            </div>

            <div class="mt-3 space-y-1 text-sm">
                <div class="font-semibold">{{ $order->number }} — {{ $order->customer->name }}</div>

                {{-- Телефон и адрес — ссылками: на телефоне это звонок и навигатор в один тап --}}
                <a href="tel:{{ $order->customer->phone }}"
                   class="inline-flex items-center gap-1.5 font-medium text-primary-600 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-phone" class="h-4 w-4" />
                    {{ $order->customer->phone }}
                </a>

                @if ($address = $assignment->address ?: $assignment->servicePoint?->address)
                    <a href="https://2gis.kz/search/{{ urlencode($address) }}" target="_blank" rel="noopener"
                       class="flex items-start gap-1.5 text-gray-700 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-map-pin" class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ $address }}</span>
                    </a>
                @endif

                @foreach ($order->items as $item)
                    <div class="text-gray-600 dark:text-gray-400">· {{ $item->name }}</div>
                @endforeach

                @if ($assignment->note)
                    <div class="mt-2 rounded-lg bg-gray-50 p-2 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                        {{ $assignment->note }}
                    </div>
                @endif

                @if ($assignment->failure_reason)
                    <div class="mt-2 rounded-lg bg-danger-50 p-2 text-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
                        {{ $assignment->failure_reason }}
                    </div>
                @endif
            </div>

            @php $outstanding = $order->outstanding(); @endphp

            @if ($outstanding > 0.01 && ! $done)
                {{-- Курьер должен видеть, сколько брать, до того как позвонит
                     в дверь: пересчитывать на пороге неудобно и стыдно. --}}
                <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3
                            dark:border-warning-700 dark:bg-warning-900/20">
                    <div class="text-sm font-semibold text-warning-800 dark:text-warning-200">
                        Получить: {{ number_format($outstanding, 0, ',', ' ') }} ₸
                    </div>

                    <form wire:submit.prevent="acceptCash({{ $assignment->id }}, $refs.cash{{ $assignment->id }}.value)"
                          class="mt-2 flex gap-2"
                          x-data
                          @submit.prevent="$wire.acceptCash({{ $assignment->id }}, $refs.cash{{ $assignment->id }}.value)">
                        <input x-ref="cash{{ $assignment->id }}"
                               type="number" inputmode="decimal" step="1" min="1"
                               value="{{ (int) $outstanding }}"
                               class="w-32 rounded-lg border-gray-300 text-base dark:border-gray-600 dark:bg-gray-800">
                        <x-filament::button type="submit" color="success" size="lg">
                            Принять
                        </x-filament::button>
                    </form>
                </div>
            @elseif (! $done)
                <div class="mt-4 text-sm font-semibold text-success-600 dark:text-success-400">
                    Заказ оплачен — деньги брать не нужно
                </div>
            @endif

            @if (! $done)
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($assignment->status->allowedTransitions($assignment->type) as $next)
                        <x-filament::button
                            wire:click="move({{ $assignment->id }}, '{{ $next->value }}')"
                            :color="$next->color()"
                            size="lg">
                            {{ $next->label() }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-gray-700">
            <div class="font-semibold">На этот день нарядов нет</div>
            <div class="mt-1 text-sm text-gray-500">Посмотрите соседние дни кнопками сверху</div>
        </div>
    @endforelse

</x-filament-panels::page>
