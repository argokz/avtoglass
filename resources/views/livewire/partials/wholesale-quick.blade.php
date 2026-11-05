{{--
    Быстрый заказ. Оптовик обычно знает коды наизусть или копирует их
    из своей таблицы — листать каталог ему незачем. Списки и прошлые
    заявки — для регулярных закупок «как в прошлый раз».
--}}
<div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
    <form wire:submit="addPasted" class="card space-y-3 p-4 sm:p-5">
        <div class="flex items-baseline justify-between gap-3">
            <label for="opt-paste" class="text-sm font-extrabold">Быстрый ввод списком</label>
            <span class="text-xs text-faint">до {{ App\Livewire\WholesaleTable::MAX_PASTED_LINES }} строк</span>
        </div>
        <textarea id="opt-paste" wire:model="pasted" rows="4" maxlength="20000"
                  class="input font-mono !text-sm @error('pasted') input-error @enderror"
                  placeholder="2001AGNMVZ 3&#10;8573AGSGNMVZ1B;2&#10;4423AGN x5"></textarea>
        @error('pasted')<p class="text-xs text-warn-ink">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="addPasted">
                <span wire:loading.remove wire:target="addPasted">Отметить по кодам</span>
                <span wire:loading wire:target="addPasted">Ищем коды…</span>
            </button>
            <span class="text-xs text-muted">Еврокод, OEM или артикул, затем количество. Можно вставить столбцы из Excel.</span>
        </div>

        @if ($ambiguous !== [])
            <div class="space-y-3 rounded-2xl border border-warn/40 bg-warn-soft p-3.5 text-sm">
                <p class="font-bold text-warn-ink">Выберите марку стекла — по этим кодам несколько вариантов</p>
                @foreach ($ambiguous as $i => $line)
                    <div wire:key="amb-{{ $line['code'] }}-{{ $i }}">
                        <p class="font-mono text-xs font-bold">{{ $line['code'] }} × {{ $line['qty'] }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($line['offers'] as $choice)
                                <button type="button" wire:click="chooseAmbiguous({{ $i }}, {{ $choice['id'] }})"
                                        class="btn btn-outline btn-sm !text-left !text-xs">{{ $choice['label'] }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($notFound !== [])
            <p class="rounded-2xl bg-sunken p-3 text-xs text-muted">
                <span class="font-bold text-ink">Нет в каталоге:</span>
                <span class="font-mono">{{ implode(', ', $notFound) }}</span>.
                Проверьте код или напишите его в комментарии — менеджер найдёт под заказ.
            </p>
        @endif
    </form>

    <div class="card space-y-4 p-4 sm:p-5">
        <div>
            <p class="text-sm font-extrabold">Мои списки</p>
            @if ($lists->isEmpty())
                <p class="mt-1 text-xs text-muted">Отметьте позиции и сохраните их списком — регулярная закупка будет в один клик.</p>
            @else
                @if ($dueToday->isNotEmpty())
                    <div class="mt-2 rounded-2xl bg-brand-soft p-3 text-sm" data-wholesale-due>
                        <p class="font-bold text-brand-ink">Сегодня по графику</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ($dueToday as $due)
                                <button type="button" wire:click="loadList({{ $due->id }})" class="btn btn-primary btn-sm">
                                    Отметить «{{ $due->name }}»
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <ul class="mt-2 divide-y divide-line">
                    @foreach ($lists as $list)
                        <li wire:key="list-{{ $list->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2">
                            <button type="button" wire:click="loadList({{ $list->id }})"
                                    class="min-w-[9rem] flex-1 text-left text-sm font-bold break-words hover:text-brand-ink"
                                    title="Добавить позиции списка к выбору">
                                {{ $list->name }} <span class="font-normal text-faint">· {{ count($list->items) }} поз.</span>
                            </button>
                            <div class="flex shrink-0 items-center gap-1">
                                <label class="sr-only" for="remind-{{ $list->id }}">Регулярная заявка «{{ $list->name }}»</label>
                                <select id="remind-{{ $list->id }}"
                                        wire:change="setReminder({{ $list->id }}, $event.target.value)"
                                        class="input !h-8 !w-auto !py-0 !text-xs">
                                    <option value="0" @selected(! $list->remind_weekday)>Не напоминать</option>
                                    @foreach (App\Models\WholesaleList::WEEKDAYS as $day => $label)
                                        <option value="{{ $day }}" @selected($list->remind_weekday === $day)>По {{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="button" wire:click="deleteList({{ $list->id }})"
                                        wire:confirm="Удалить список «{{ $list->name }}»?"
                                        class="px-1.5 text-faint hover:text-brand-ink"
                                        aria-label="Удалить список {{ $list->name }}">✕</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-1.5 text-xs text-muted">
                    В выбранный день список ждёт первым на этой странице{{ $canRemind ? ', а в 9 утра придёт напоминание в WhatsApp на номер из кабинета' : '' }}.
                    Заявку отправляете вы — по сегодняшним ценам.
                </p>
            @endif
        </div>
        @if ($recent->isNotEmpty())
            <div class="border-t border-line pt-4">
                <p class="text-sm font-extrabold">Повторить прошлую заявку</p>
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($recent as $past)
                        <li class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 truncate text-muted">
                                <span class="font-bold text-ink">{{ $past->number }}</span>
                                · {{ $past->created_at->translatedFormat('j M') }} · {{ $past->items_count }} поз.
                            </span>
                            <a href="{{ route('wholesale.index', ['repeat' => $past->id]) }}"
                               class="shrink-0 text-xs font-bold text-brand-ink underline-offset-4 hover:underline">Повторить</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

@if ($flash !== '')
    <p class="mt-3 text-sm font-bold text-ok-ink" role="status" wire:key="flash-{{ md5($flash) }}">{{ $flash }}</p>
@endif
