<x-filament-panels::page>
    <form wire:submit="import" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">
                Разобрать прайс
            </x-filament::button>
        </div>
    </form>

    <div class="mt-10">
        <h2 class="text-base font-semibold">Последние загрузки</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Сначала разбор в стейджинг, потом «Собрать каталог» — иначе витрина не изменится.
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
                                    @if (in_array($run->status, ['parsed', 'applied'], true))
                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            wire:click="build({{ $run->id }})"
                                            wire:loading.attr="disabled"
                                        >
                                            Собрать каталог
                                        </x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
