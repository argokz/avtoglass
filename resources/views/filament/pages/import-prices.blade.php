<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                Разобрать прайс
            </x-filament::button>
        </div>
    </form>

    @if ($previewRun && $preview !== [])
        @php
            $карточки = [
                ['Новых карточек', $preview['products_new'], 'Появятся на витрине, если не уйдут на проверку'],
                ['Новых предложений', $preview['offers_new'], 'Поставщик или артикул, которых раньше не было'],
                ['Цена выросла', $preview['price_up'], null],
                ['Цена снизилась', $preview['price_down'], null],
                ['Без изменений', $preview['price_same'], null],
                ['Уйдут из продажи', $preview['deactivated'], 'В прайсе без цены'],
            ];
        @endphp
        {{-- В панели нет своей темы Tailwind: раскладка — встроенными стилями и компонентами Filament. --}}
        <x-filament::section icon="heroicon-o-clipboard-document-check" style="margin-top: 2rem">
            <x-slot name="heading">Импорт #{{ $previewRun }}: что изменится в каталоге</x-slot>
            <x-slot name="description">
                В сборку пойдёт {{ $preview['rows'] }} строк, {{ $preview['skipped'] }} пропущено (не стекло или нет марки),
                {{ $preview['review'] }} на проверке — такие карточки собираются скрытыми.
                Предложения, которых нет в этом прайсе, сборка не трогает.
            </x-slot>
            <x-slot name="afterHeader">
                <x-filament::button color="gray" size="sm" wire:click="$set('previewRun', null)">Скрыть</x-filament::button>
            </x-slot>

            <dl style="display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: .75rem; margin: 0">
                @foreach ($карточки as [$подпись, $число, $пояснение])
                    <div style="border: 1px solid rgb(127 127 127 / .25); border-radius: .75rem; padding: .75rem 1rem" @if ($пояснение) title="{{ $пояснение }}" @endif>
                        <dt style="font-size: .75rem; opacity: .7">{{ $подпись }}</dt>
                        <dd style="margin: .25rem 0 0; font-size: 1.375rem; font-weight: 600; font-variant-numeric: tabular-nums">{{ number_format($число, 0, ',', ' ') }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($preview['suspicious'] !== [])
                <div style="margin-top: 1.5rem">
                    <x-filament::badge color="warning" icon="heroicon-o-exclamation-triangle">
                        Проверьте глазами: цена изменилась вдвое и больше
                    </x-filament::badge>
                    <div style="overflow-x: auto; margin-top: .75rem">
                        <table style="width: 100%; border-collapse: collapse; font-size: .875rem">
                            <thead>
                                <tr style="text-align: left; font-size: .75rem; opacity: .7">
                                    <th style="padding: .25rem 1rem .25rem 0">Строка прайса</th>
                                    <th style="padding: .25rem 1rem .25rem 0">Поставщик</th>
                                    <th style="padding: .25rem 1rem .25rem 0; text-align: right">Было</th>
                                    <th style="padding: .25rem 0; text-align: right">Станет</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($preview['suspicious'] as $скачок)
                                    <tr style="border-top: 1px solid rgb(127 127 127 / .2)">
                                        <td style="padding: .4rem 1rem .4rem 0">{{ \Illuminate\Support\Str::limit($скачок['name'], 90) }}</td>
                                        <td style="padding: .4rem 1rem .4rem 0; white-space: nowrap">{{ $скачок['supplier'] }}</td>
                                        <td style="padding: .4rem 1rem .4rem 0; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums">{{ number_format($скачок['old'], 0, ',', ' ') }} ₸</td>
                                        <td style="padding: .4rem 0; text-align: right; white-space: nowrap; font-weight: 600; font-variant-numeric: tabular-nums">{{ number_format($скачок['new'], 0, ',', ' ') }} ₸</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div style="display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.5rem">
                <x-filament::button wire:click="build({{ $previewRun }})" wire:loading.attr="disabled" wire:target="build"
                                    wire:confirm="Собрать каталог из импорта #{{ $previewRun }}? Цены на витрине обновятся сразу.">
                    Собрать каталог
                </x-filament::button>
                <span style="font-size: .75rem; opacity: .7">Цены на витрине обновятся сразу после сборки.</span>
            </div>
        </x-filament::section>
    @endif

    <div class="mt-10">
        <h2 class="text-base font-semibold">Последние загрузки</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Сначала разбор, потом «Что изменится» — и только после сводки «Собрать каталог».
        </p>

        @if ($this->runs()->isEmpty())
            <p class="mt-4 rounded-xl border border-gray-200 p-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                Прайс ещё не загружали. Команда та же: <code>php artisan price:import файл.xlsx</code>
            </p>
        @else
            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2">#</th>
                            <th class="px-4 py-2">Файл</th>
                            <th class="px-4 py-2">Статус</th>
                            <th class="px-4 py-2">Строк</th>
                            <th class="px-4 py-2">Разобрано / проверка</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($this->runs() as $run)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $run->id }}</td>
                                <td class="px-4 py-2">
                                    <div class="font-medium">{{ $run->filename }}</div>
                                    @if ($run->source)
                                        <div class="text-xs text-gray-500">{{ $run->source }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $run->status }}</td>
                                <td class="px-4 py-2">{{ $run->rows_total }}</td>
                                <td class="px-4 py-2">{{ $run->rows_parsed }} / {{ $run->rows_review }}</td>
                                <td class="px-4 py-2 text-right">
                                    <div style="display: flex; justify-content: flex-end; gap: .5rem; flex-wrap: wrap">
                                        @if ($run->rows_review > 0)
                                            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-down-tray"
                                                                wire:click="downloadReview({{ $run->id }})">
                                                На проверку · {{ $run->rows_review }}
                                            </x-filament::button>
                                        @endif
                                        @if (in_array($run->status, ['parsed', 'applied'], true))
                                            <x-filament::button size="sm" wire:click="preview({{ $run->id }})"
                                                                wire:loading.attr="disabled" wire:target="preview({{ $run->id }})">
                                                Что изменится
                                            </x-filament::button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
