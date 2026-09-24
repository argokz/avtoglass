<div data-chat-selection>
    <p class="mb-4 text-sm text-muted">{{ __('shop.chat_shared_note') }}</p>
    <x-shop.summary :quote="$quote" :live="true">
        <ul class="space-y-3 text-sm">@foreach ($cart as $id => $quantity)<li class="flex flex-wrap items-center justify-between gap-2"><span>{{ $offers[$id]['article'] }} · {{ $offers[$id]['manufacturer'] }} × {{ $quantity }}</span><button type="button" wire:click="toggle({{ $id }})" class="min-h-11 font-bold underline" aria-label="{{ __('shop.remove') }} {{ $offers[$id]['article'] }}">{{ __('shop.remove') }}</button></li>@endforeach</ul>
        @if ($assistantReviewIds)<p class="mt-3 text-sm text-warn-ink">{{ __('shop.chat_fitment_review') }}</p>@endif
        <button type="button" wire:click="prepareChatCheckout" x-on:click="$refs.chatSummaryDialog?.close()" class="btn btn-primary mt-5 min-h-11 w-full" @disabled(! $cart && ! $unresolvedRows)>{{ __('shop.chat_prepare_order') }}</button>
    </x-shop.summary>
</div>
