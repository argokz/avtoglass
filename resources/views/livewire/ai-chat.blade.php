{{--
    Два вида: плавающая кнопка слева внизу (WhatsApp остаётся справа)
    и встроенный чат на главной — подбор без каталога.
--}}
<div x-data data-ai-chat="{{ $embedded ? 'embedded' : 'floating' }}"
     @class([
         'flex min-h-0 flex-col overflow-hidden rounded-card border border-line bg-surface shadow-card' => $embedded,
         'fixed left-4 z-40' => ! $embedded,
     ])
     style="{{ $embedded ? 'height: clamp(24rem, calc(100dvh - 20rem), 52rem);' : 'bottom: calc(1rem + env(safe-area-inset-bottom, 0px));' }}">
    @if ($embedded)
        @include('livewire.partials.ai-chat-thread')
    @else
        @if ($open)
            <div class="card mb-3 flex h-[26rem] w-[min(92vw,22rem)] flex-col !p-0 shadow-lift">
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-extrabold">Помощник</p>
                        <p class="text-xs text-muted">Подберёт стекло и оформит заявку</p>
                    </div>
                    <button type="button" wire:click="$toggle('open')"
                            class="rounded-lg p-1.5 text-faint transition hover:bg-sunken hover:text-ink"
                            aria-label="Свернуть чат">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                @include('livewire.partials.ai-chat-thread')
            </div>
        @endif

        <button type="button" wire:click="$toggle('open')"
                class="btn btn-primary !gap-2 !rounded-full !px-4 !py-3 shadow-lift"
                aria-expanded="{{ $open ? 'true' : 'false' }}"
                aria-label="Чат с помощником">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM21 12c0 4.556-4.03 8.25-9 8.25a9.76 9.76 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/>
            </svg>
            <span class="text-sm font-bold">Помощник</span>
        </button>
    @endif
</div>
