<div class="flex min-h-0 flex-1 flex-col">
    <div class="flex-1 space-y-5 overflow-y-auto px-4 py-5 sm:px-6" data-ai-chat-scroll role="log" aria-label="Диалог с помощником" aria-relevant="additions text">
        @foreach ($messages as $message)
            @if ($message['role'] === 'user')
                <div class="ml-auto max-w-[90%] rounded-2xl rounded-br-md bg-sunken px-4 py-3 text-base leading-relaxed sm:max-w-[80%]">
                    <span class="sr-only">Вы: </span>
                    {!! nl2br(e($message['text'])) !!}
                </div>
            @else
                <div class="mr-4 max-w-3xl text-base leading-relaxed sm:mr-12">
                    <span class="mb-1 block text-xs font-bold text-muted">Помощник</span>
                    {!! nl2br(e($message['text'])) !!}
                </div>
            @endif
        @endforeach

        <div wire:loading wire:target="send" class="mr-8 rounded-2xl rounded-bl-md bg-sunken px-3.5 py-2.5 text-sm text-muted">
            Печатает…
        </div>
    </div>

    {{-- Не <form>: без Livewire Enter уходил бы GET на /? и стирал диалог. --}}
    <div class="flex items-end gap-2 border-t border-line p-3 sm:p-4">
        <textarea wire:model="draft" maxlength="1000" autocomplete="off" rows="2"
               disabled x-bind:disabled="false"
               aria-label="Сообщение помощнику" enterkeyhint="send" inputmode="text"
               placeholder="Camry 2018, лобовое…"
               class="input resize-none !rounded-xl !py-3 !text-base"
               x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $refs.sendMessage.click(); }"
               wire:loading.attr="disabled" wire:target="send"></textarea>
        <button type="button" wire:click="send" x-ref="sendMessage" class="btn btn-primary !min-h-11 !min-w-11 !rounded-xl !p-3"
                disabled x-bind:disabled="false"
                wire:loading.attr="disabled" wire:target="send"
                aria-label="Отправить">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0-5.25-5.25M19.5 12l-5.25 5.25"/>
            </svg>
        </button>
    </div>
    <noscript><p class="px-4 pb-4 text-sm text-muted">Для диалога включите JavaScript или <a href="{{ route('catalog.brands') }}" class="underline">подберите стекло в каталоге</a>.</p></noscript>
</div>
