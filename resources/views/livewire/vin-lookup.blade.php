<div class="mx-auto max-w-2xl">

    <label for="vin" class="mb-1.5 block text-sm font-semibold">VIN автомобиля</label>

    <div class="flex gap-2">
        <input id="vin" type="text" wire:model.live.debounce.400ms="vin"
               maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
               inputmode="latin" placeholder="XW8ZZZ61ZKG012345"
               class="w-full rounded-xl border border-line bg-surface px-4 py-3 font-mono text-base tracking-widest
                      uppercase placeholder:tracking-normal placeholder:text-faint placeholder:normal-case
                      focus:border-brand focus:outline-none">

        @if ($vin !== '')
            <button type="button" wire:click="clear" aria-label="Очистить"
                    class="shrink-0 rounded-xl border border-line px-4 text-sm font-semibold text-muted
                           transition hover:border-brand/40 hover:text-ink">
                Очистить
            </button>
        @endif
    </div>

    <p class="mt-2 text-xs text-faint">
        17 знаков. Есть в свидетельстве о регистрации, на стойке водительской двери
        и под лобовым стеклом слева.
    </p>

    {{-- Пока идёт разбор, поле не должно выглядеть замершим --}}
    <div wire:loading.delay wire:target="vin" class="mt-4 text-sm text-muted">Разбираем…</div>

    <div wire:loading.remove wire:target="vin">

        @if ($problem)
            <div class="mt-4 rounded-card border border-line bg-warn-soft px-4 py-3 text-sm text-warn-ink">
                {{ $problem }}
            </div>
        @endif

        @if ($parsed)
            <div class="mt-5 rounded-card border border-line bg-surface p-5">
                <p class="text-xs font-semibold tracking-wide text-muted uppercase">Что говорит VIN</p>

                <dl class="mt-3 space-y-2 text-sm">
                    @if ($brandName)
                        <div class="flex gap-3">
                            <dt class="w-32 shrink-0 text-muted">Марка</dt>
                            <dd class="font-semibold">{{ $brandName }}</dd>
                        </div>
                    @endif

                    @if ($parsed->country())
                        <div class="flex gap-3">
                            <dt class="w-32 shrink-0 text-muted">Сборка</dt>
                            <dd>{{ $parsed->country() }}</dd>
                        </div>
                    @endif

                    @if ($parsed->year())
                        <div class="flex gap-3">
                            <dt class="w-32 shrink-0 text-muted">Год выпуска</dt>
                            <dd>
                                <span class="font-semibold">{{ $parsed->year() }}</span>
                                {{-- Буквенный код года повторяется каждые 30 лет.
                                     Молчать об этом нельзя: стекло 1995 и 2025 года разное. --}}
                                @if ($parsed->alternativeYear())
                                    <span class="text-muted">или {{ $parsed->alternativeYear() }} —
                                    код года повторяется раз в 30 лет, уточните по документам</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                </dl>

                {{-- Честная граница функции: модель из VIN не достаётся,
                     и делать вид, что мы её знаем, значит подобрать не то стекло. --}}
                <p class="mt-4 border-t border-line pt-3 text-xs text-faint">
                    Модель по VIN бесплатно не определяется — её кодирует сам завод.
                    Выберите её ниже, а VIN мы приложим к заявке: менеджер сверит точно.
                </p>
            </div>

            @if ($brand && $models->isNotEmpty())
                <p class="mt-6 text-sm font-semibold">
                    Модели {{ $brand->name }}@if ($parsed->year()), стекло подберём на {{ $parsed->year() }} год @endif
                </p>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($models as $model)
                        <a href="{{ route('catalog.products', [$brand, $model] + array_filter(['year' => $parsed->year()])) }}"
                           wire:navigate
                           class="rounded-card border border-line bg-surface px-4 py-3 text-sm font-semibold
                                  transition hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-card">
                            {{ $model->name }}
                        </a>
                    @endforeach
                </div>
            @elseif ($brandName)
                <div class="mt-5 rounded-card border border-line bg-surface px-5 py-6 text-sm">
                    <p class="font-semibold">{{ $brandName }} — такой марки в каталоге пока нет</p>
                    <p class="mt-1 text-muted">
                        Это не значит, что стекла нет: под заказ возим и то, чего нет на витрине.
                        Оставьте заявку — VIN уже приложен, менеджер найдёт по нему.
                    </p>
                    <a href="{{ route('order.form') }}" wire:navigate
                       class="mt-4 inline-block rounded-pill bg-brand px-5 py-2.5 text-sm font-semibold
                              text-on-brand transition hover:bg-brand-hover">
                        Оставить заявку
                    </a>
                </div>
            @else
                <div class="mt-5 rounded-card border border-line bg-surface px-5 py-6 text-sm">
                    <p class="font-semibold">Марку по этому VIN мы не опознали</p>
                    <p class="mt-1 text-muted">
                        Справочник заводов у нас неполный, и врать не хочется.
                        Год и страну прочитали — выберите марку сами.
                    </p>
                    <a href="{{ route('catalog.brands') }}" wire:navigate
                       class="mt-4 inline-block rounded-pill bg-brand px-5 py-2.5 text-sm font-semibold
                              text-on-brand transition hover:bg-brand-hover">
                        Выбрать марку
                    </a>
                </div>
            @endif
        @endif

    </div>
</div>
