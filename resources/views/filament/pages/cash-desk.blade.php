<x-filament-panels::page>

    @if ($this->holders->isEmpty())
        <x-filament::section>
            <div class="text-center py-6">
                <p class="text-base font-semibold">Наличных на руках нет</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Всё, что приняли от клиентов, сдано в кассу.
                </p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-4">
            @foreach ($this->holders as $holder)
                <x-filament::section>
                    <x-slot name="heading">
                        {{ $holder->user->name }}
                    </x-slot>

                    <x-slot name="description">
                        {{ $holder->count }}
                        {{ trans_choice('платёж|платежа|платежей', $holder->count) }}
                        @if ($holder->oldest)
                            {{-- Самое старое важнее суммы: деньги, которые везут
                                 третий день, это уже не поездка --}}
                            · самый ранний {{ \Illuminate\Support\Carbon::parse($holder->oldest)->diffForHumans() }}
                        @endif
                    </x-slot>

                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Числится за сотрудником</p>
                            <p class="text-3xl font-bold tracking-tight">
                                {{ number_format($holder->total, 0, ',', ' ') }} ₸
                            </p>
                        </div>

                        <div class="flex items-end gap-2">
                            <div>
                                <label for="counted-{{ $holder->user->id }}"
                                       class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                                    Пересчитано в руках, ₸
                                </label>
                                <input id="counted-{{ $holder->user->id }}" type="number" step="0.01"
                                       wire:model="counted.{{ $holder->user->id }}"
                                       placeholder="{{ number_format($holder->total, 0, '', '') }}"
                                       class="w-40 rounded-lg border-gray-300 text-sm shadow-sm
                                              dark:border-gray-600 dark:bg-gray-900">
                            </div>

                            <x-filament::button wire:click="accept({{ $holder->user->id }})"
                                                wire:loading.attr="disabled"
                                                color="success">
                                Принять в кассу
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Из чего складывается сумма: при расхождении разбираться
                         будут по конкретным заказам, а не по итогу --}}
                    <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-700">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($this->detailsFor($holder->user->id) as $payment)
                                    <tr>
                                        <td class="py-1.5 font-medium">
                                            {{ $payment->order?->number ?? '—' }}
                                        </td>
                                        <td class="py-1.5 text-gray-500 dark:text-gray-400">
                                            {{ $payment->order?->customer?->name }}
                                        </td>
                                        <td class="py-1.5 text-gray-500 dark:text-gray-400">
                                            {{ $payment->paid_at?->format('d.m H:i') }}
                                        </td>
                                        <td class="py-1.5 text-right font-semibold whitespace-nowrap">
                                            {{ number_format((float) $payment->amount, 0, ',', ' ') }} ₸
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

</x-filament-panels::page>
