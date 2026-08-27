<div class="mx-auto max-w-2xl">

@if ($booked)
    {{-- Подтверждение вместо формы: человек должен увидеть номер и время,
         а не гадать, прошла ли запись. --}}
    <div class="rounded-card border border-line bg-surface p-6 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand-soft text-brand-ink">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M20 6 9 17l-5-5" />
            </svg>
        </span>

        <h2 class="mt-4 font-display text-xl font-bold">Записали, заявка {{ $booked }}</h2>
        <p class="mt-2 text-sm text-muted">
            Ждём вас {{ \Illuminate\Support\Carbon::parse($chosen)->translatedFormat('j F, H:i') }}
            — {{ $points->firstWhere('id', $pointId)?->name }}.
        </p>
        <p class="mt-1 text-sm text-muted">
            {{ $points->firstWhere('id', $pointId)?->address }}
        </p>
        <p class="mx-auto mt-4 max-w-md text-xs text-faint">
            Менеджер позвонит, чтобы подтвердить наличие стекла. Если планы поменяются,
            перезвоните — освободим время для других.
        </p>

        <a href="{{ route('home') }}" wire:navigate
           class="mt-5 inline-block rounded-pill bg-brand px-6 py-2.5 text-sm font-semibold text-on-brand
                  transition hover:bg-brand-hover">
            На главную
        </a>
    </div>
@else

    @if ($product)
        <div class="mb-5 rounded-card border border-line bg-surface px-4 py-3 text-sm">
            <span class="text-muted">Записываетесь на замену:</span>
            <span class="font-semibold">{{ $product->name }}</span>
        </div>
    @endif

    {{-- Сервис. Показываем выбор только когда точек больше одной. --}}
    @if ($points->count() > 1)
        <p class="text-sm font-semibold">Куда приедете</p>

        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            @foreach ($points as $point)
                <button type="button" wire:click="selectPoint({{ $point->id }})"
                        class="rounded-card border px-4 py-3 text-left transition
                               {{ $pointId === $point->id
                                   ? 'border-brand bg-brand-soft'
                                   : 'border-line bg-surface hover:border-brand/40' }}">
                    <span class="block text-sm font-semibold">{{ $point->name }}</span>
                    <span class="mt-0.5 block text-xs text-muted">{{ $point->address }}</span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($days->isEmpty())
        <div class="mt-5 rounded-card border border-line bg-surface px-5 py-8 text-center text-sm">
            <p class="font-semibold">Свободного времени сейчас нет</p>
            <p class="mt-1 text-muted">
                Все посты заняты на две недели вперёд. Оставьте заявку — менеджер
                предложит время, как только освободится.
            </p>
            <a href="{{ route('order.form') }}" wire:navigate
               class="mt-4 inline-block rounded-pill bg-brand px-5 py-2.5 text-sm font-semibold text-on-brand">
                Оставить заявку
            </a>
        </div>
    @else
        {{-- Дни без окон в выбор не попадают: пустой день заставляет тыкать наугад --}}
        <p class="mt-5 text-sm font-semibold">Когда удобно</p>

        <div class="scroll-row mt-2" role="group" aria-label="Выбор дня">
            @foreach ($days as $option)
                @php($isSelected = $selected && $option->toDateString() === $selected->toDateString())
                <button type="button" wire:click="selectDay('{{ $option->toDateString() }}')"
                        class="rounded-card border px-4 py-2.5 text-center transition
                               {{ $isSelected ? 'border-brand bg-brand text-on-brand' : 'border-line bg-surface hover:border-brand/40' }}">
                    <span class="block text-xs {{ $isSelected ? 'opacity-80' : 'text-faint' }}">
                        {{ $option->translatedFormat('D') }}
                    </span>
                    <span class="block text-sm font-semibold whitespace-nowrap">
                        {{ $option->translatedFormat('j M') }}
                    </span>
                </button>
            @endforeach
        </div>

        <div class="mt-4 flex items-baseline justify-between gap-3">
            <p class="text-sm font-semibold">Во сколько</p>
            {{-- Длительность говорим сразу: человеку надо спланировать день --}}
            <p class="text-xs text-faint">
                Замена займёт около {{ intdiv($duration, 60) }} ч
                @if ($duration % 60) {{ $duration % 60 }} мин @endif
            </p>
        </div>

        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($times as $time)
                <button type="button" wire:click="selectSlot('{{ $time->toDateTimeString() }}')"
                        class="rounded-pill border px-4 py-2.5 text-sm font-semibold transition
                               {{ $chosen === $time->toDateTimeString()
                                   ? 'border-brand bg-brand text-on-brand'
                                   : 'border-line bg-surface hover:border-brand/40' }}">
                    {{ $time->format('H:i') }}
                </button>
            @endforeach
        </div>

        @if ($error)
            <div class="mt-4 rounded-card border border-line bg-warn-soft px-4 py-3 text-sm text-warn-ink">
                {{ $error }}
            </div>
        @endif

        {{-- Контакты спрашиваем последними: пока время не выбрано, они ни к чему --}}
        @if ($chosen)
            <form wire:submit="book" class="mt-6 rounded-card border border-line bg-surface p-5">
                <p class="text-sm">
                    <span class="text-muted">Выбрано:</span>
                    <span class="font-semibold">
                        {{ \Illuminate\Support\Carbon::parse($chosen)->translatedFormat('j F, H:i') }}
                    </span>
                </p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="sp-name" class="mb-1 block text-xs font-semibold text-muted">Как вас зовут</label>
                        <input id="sp-name" type="text" wire:model="name" maxlength="120"
                               class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                                      focus:border-brand focus:outline-none">
                        @error('name') <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="sp-phone" class="mb-1 block text-xs font-semibold text-muted">Телефон</label>
                        <input id="sp-phone" type="tel" wire:model="phone" maxlength="32"
                               placeholder="+7 700 000 00 00"
                               class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                                      placeholder:text-faint focus:border-brand focus:outline-none">
                        @error('phone') <p class="mt-1 text-xs text-warn-ink">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-3">
                    <label for="sp-comment" class="mb-1 block text-xs font-semibold text-muted">
                        Машина и что случилось
                    </label>
                    <textarea id="sp-comment" wire:model="comment" rows="2" maxlength="2000"
                              placeholder="Toyota Camry 2018, трещина через всё стекло"
                              class="w-full rounded-xl border border-line bg-surface px-4 py-3 text-sm
                                     placeholder:text-faint focus:border-brand focus:outline-none"></textarea>
                </div>

                <button type="submit" wire:loading.attr="disabled" wire:target="book"
                        class="mt-4 w-full rounded-pill bg-brand px-6 py-3 text-sm font-semibold text-on-brand
                               transition hover:bg-brand-hover disabled:opacity-60">
                    <span wire:loading.remove wire:target="book">Записаться</span>
                    <span wire:loading wire:target="book">Записываем…</span>
                </button>

                <p class="mt-2 text-center text-xs text-faint">
                    Менеджер позвонит подтвердить наличие стекла
                </p>
            </form>
        @endif
    @endif
@endif

</div>
