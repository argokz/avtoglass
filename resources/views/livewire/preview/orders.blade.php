<div class="flex flex-wrap items-center justify-between gap-4"><h1 class="text-3xl font-extrabold">{{ $screen === 'orders' ? __('shop.orders') : __('shop.order_number', ['number' => $demoOrder['number']]) }}</h1><button type="button" wire:click="go('catalog')" class="btn btn-outline min-h-11">{{ __('shop.new_selection') }}</button></div>
<button type="button" wire:click="go('orders')" class="mt-3 min-h-11 text-sm font-bold underline">{{ __('shop.history_back') }}</button>
@error('history')<x-shop.notice tone="warning" class="mt-4" role="alert">{{ $message }}</x-shop.notice>@enderror
@if ($scenario === 'empty')
    <x-shop.empty class="mt-6" :title="__('shop.empty_title')"><button type="button" wire:click="go('catalog')" class="btn btn-primary min-h-11">{{ __('shop.catalog') }}</button></x-shop.empty>
@else
    <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_21rem]">
        <section class="rounded-card border border-line bg-surface p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-extrabold">{{ __('shop.order_number', ['number' => $demoOrder['number']]) }}</h2><x-shop.status :state="$demoOrder['status']" /></div>
            <p class="mt-2 text-sm text-muted">{{ \Carbon\CarbonImmutable::parse($demoOrder['created_at'] ?? '2026-09-17')->format('d.m.Y') }} · {{ __('shop.'.$demoOrder['mode']) }} · {{ __('shop.'.($demoOrder['fulfillment'] === 'delivery' ? 'shipping' : $demoOrder['fulfillment'])) }}</p>
            <div class="mt-5 divide-y divide-line">@foreach ($demoOrder['cart'] as $id => $quantity)<div class="flex flex-wrap justify-between gap-3 py-3"><div class="min-w-0"><p class="font-semibold">{{ __('shop.'.($demoOrder['items'][$id]['title'] ?? $offers[$id]['title'])) }}</p><p class="mt-2 text-sm text-muted">{{ $demoOrder['items'][$id]['article'] ?? $offers[$id]['article'] }} · {{ $demoOrder['items'][$id]['manufacturer'] ?? $offers[$id]['manufacturer'] }}</p><p class="mt-2 text-sm text-muted">{{ __('shop.quantity') }}: {{ $quantity }}</p></div><div><x-shop.price :amount="$demoOrder['prices'][$id] ?? null" /><p class="mt-1 text-xs text-muted">{{ __('shop.unit_price') }}</p></div></div>@endforeach</div>
            <div class="mt-4 border-t border-line pt-4"><x-shop.price :amount="$demoOrder['total']" class="text-2xl" /></div>
            @include('livewire.preview.receipt-snapshot', ['receipt' => $demoOrder])
            <div class="mt-6 flex flex-wrap gap-3"><button type="button" wire:click="repeatOrder" class="btn btn-primary min-h-11">{{ __('shop.repeat') }}</button>
                @if ($this->orderEditReason() === null)<button type="button" wire:click="editOrder" class="btn btn-outline min-h-11">{{ __('shop.edit') }}</button>
                @elseif ($this->canRequestChange())<button type="button" wire:click="$toggle('showChange')" class="btn btn-outline min-h-11">{{ __('shop.request_change') }}</button>@endif
            </div>
            @if ($this->orderEditReason())<p class="mt-4 text-sm text-muted">{{ __('shop.'.$this->orderEditReason()) }}</p>@else<button type="button" wire:click="cancelUnconfirmed({{ $demoOrder['version'] }})" wire:confirm="{{ __('shop.history_cancel_confirm') }}" class="mt-3 min-h-11 text-sm font-semibold underline">{{ __('shop.history_cancel') }}</button>@endif
            @if ($showChange && $this->canRequestChange())
                <form wire:submit="submitChange" class="mt-6 border-t border-line pt-5">
                    <x-shop.notice>{{ __('shop.change_explanation') }}</x-shop.notice>
                    <label for="preview-change" class="mt-5 mb-2 block font-bold">{{ __('shop.change_text') }}</label><textarea id="preview-change" wire:model="changeText" rows="3" class="input !text-base" placeholder="{{ __('shop.change_hint') }}" @error('changeText') aria-invalid="true" aria-describedby="preview-change-error" @enderror></textarea>
                    @error('changeText')<p id="preview-change-error" class="mt-2 text-sm text-warn-ink">{{ $message }}</p>@enderror
                    <button type="submit" class="btn btn-primary mt-4 min-h-11" wire:loading.attr="disabled">{{ __('shop.send_change') }}</button>
                </form>
            @endif
        </section>
        <aside class="space-y-5">
            @include('livewire.preview.order-settlement')
            @if ($demoOrder['events'] ?? [])<section class="rounded-card border border-line bg-surface p-5"><h2 class="font-extrabold">{{ __('shop.history_events') }}</h2><ol class="mt-4 space-y-3">@foreach ($demoOrder['events'] as $event)<li class="text-sm"><p class="font-semibold">{{ __('shop.'.$event['kind']) }}</p><time class="text-muted">{{ \Carbon\CarbonImmutable::parse($event['at'])->format('d.m.Y H:i') }}</time></li>@endforeach</ol></section>@endif
            @if ($demoOrder['changes'])<section class="rounded-card border border-line bg-surface p-5"><h2 class="font-extrabold">{{ __('shop.history') }}</h2><ol class="mt-5 space-y-5 border-l-2 border-line pl-4 text-sm">@foreach ($demoOrder['changes'] as $change)<li><p class="font-bold">{{ __('shop.change_sent') }}</p><p class="mt-1 break-words text-muted">{{ $change }}</p></li>@endforeach</ol></section>@endif
            <section class="rounded-xl border border-dashed border-line-strong p-4"><p class="text-xs font-bold text-muted">{{ __('shop.scenario') }}</p><div class="mt-3 flex flex-wrap gap-2">@foreach (['new', 'pending', 'confirmed', 'completed'] as $state)<button type="button" wire:click="setOrderState('{{ $state }}')" class="min-h-11 rounded-pill border border-line bg-surface px-3 text-sm" aria-pressed="{{ $demoOrder['status'] === $state ? 'true' : 'false' }}">{{ __('shop.status_'.$state) }}</button>@endforeach</div></section>
        </aside>
    </div>
    @include('livewire.preview.manager-proposal')
    @include('livewire.preview.manager-editor')
    @if ($demoOrder['proposalHistory'] ?? [])<details class="mt-6 rounded-card border border-line bg-surface p-5"><summary class="min-h-11 cursor-pointer font-bold">{{ __('shop.proposal_archive') }}</summary><div class="mt-4 grid gap-4 md:grid-cols-2">@foreach ($demoOrder['proposalHistory'] as $oldProposal)<section class="min-w-0 rounded-xl border border-line p-4"><h3 class="font-bold">{{ __('shop.proposal_version', ['version' => $oldProposal['version']]) }} · {{ __('shop.proposal_'.$oldProposal['status']) }}</h3><x-shop.price :amount="$oldProposal['total']" />@include('livewire.preview.proposal-terms', ['terms' => $oldProposal])</section>@endforeach</div></details>@endif
    <div class="mt-6 rounded-xl border border-dashed border-line-strong p-4"><p class="text-xs text-muted">{{ __('shop.scenario') }}</p><button type="button" wire:click="loadRepeatExample" class="btn btn-outline mt-3 min-h-11">{{ __('shop.repeat_demo') }}</button></div>
@endif
