@if (! $this->signedIn())
    @include('livewire.preview.cabinet-login')
@else
    @php $history = $this->historyResults(); @endphp
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h1 id="history-title" tabindex="-1" class="text-3xl font-extrabold">{{ __('shop.orders') }}</h1><p class="mt-3 text-muted">{{ __('shop.history_intro') }}</p></div>
        <button type="button" wire:click="logoutCabinet" class="btn btn-outline min-h-11">{{ __('shop.logout') }}</button>
    </div>
    <section class="mt-6 rounded-card border border-line bg-surface p-5" aria-label="{{ __('shop.history_filters') }}">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-shop.field name="history-search" :label="__('shop.history_search')" type="search" wire:model.live.debounce.350ms="orderSearch" maxlength="100" />
            <label class="block text-sm font-bold">{{ __('shop.history_status') }}<select wire:model.live="orderStatus" class="select mt-2 w-full"><option value="all">{{ __('shop.history_all_statuses') }}</option>@foreach (['new', 'pending', 'confirmed', 'scheduled', 'completed', 'cancelled'] as $state)<option value="{{ $state }}">{{ __('shop.status_'.$state) }}</option>@endforeach</select></label>
            <label class="block text-sm font-bold">{{ __('shop.history_mode') }}<select wire:model.live="orderMode" class="select mt-2 w-full"><option value="all">{{ __('shop.history_all_modes') }}</option><option value="retail">{{ __('shop.retail') }}</option><option value="wholesale">{{ __('shop.wholesale') }}</option></select></label>
            <label class="block text-sm font-bold">{{ __('shop.history_period') }}<select wire:model.live="orderPeriod" class="select mt-2 w-full"><option value="all">{{ __('shop.history_all_periods') }}</option><option value="30">{{ __('shop.history_days', ['count' => 30]) }}</option><option value="90">{{ __('shop.history_days', ['count' => 90]) }}</option></select></label>
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3"><p class="text-sm text-muted" role="status">{{ __('shop.history_count', ['count' => $history['total']]) }} <span wire:loading wire:target="orderSearch,orderStatus,orderMode,orderPeriod,historyPage">{{ __('shop.history_loading') }}</span></p><button type="button" wire:click="resetHistoryFilters" class="min-h-11 text-sm font-bold underline">{{ __('shop.history_reset') }}</button></div>
    </section>
    @error('history')<x-shop.notice tone="warning" class="mt-5" role="alert">{{ $message }}</x-shop.notice>@enderror
    <div class="mt-6 space-y-4" data-order-history>
        @forelse ($history['rows'] as $order)
            <article class="grid items-start gap-5 rounded-card border border-line bg-surface p-5 md:grid-cols-[minmax(0,1fr)_16rem]" wire:key="history-{{ $order['number'] }}-{{ $order['version'] }}" data-history-order="{{ $order['number'] }}">
                <div class="min-w-0"><div class="flex flex-wrap items-center gap-3"><h2 class="text-xl font-extrabold">{{ $order['number'] }}</h2><x-shop.status :state="$order['status']" /></div><p class="mt-2 text-sm text-muted">{{ \Carbon\CarbonImmutable::parse($order['created_at'])->format('d.m.Y') }} · {{ __('shop.'.$order['mode']) }}</p><p class="mt-3 break-words">{{ implode(', ', array_map(fn ($item) => __('shop.'.$item['title']), array_slice($order['items'], 0, 2))) }}</p><p class="mt-2 text-sm text-muted">{{ __('shop.positions', ['count' => count($order['cart'])]) }}</p></div>
                <div><x-shop.price :amount="$order['total']" class="text-xl" /><p class="mt-2 text-sm">{{ __('shop.payment_'.$order['payment']) }}</p><p class="mt-3 text-sm text-muted">{{ __('shop.history_next_'.match ($order['status']) { 'new', 'pending' => 'review', 'confirmed' => 'receive', 'scheduled' => 'scheduled', default => 'repeat' }) }}</p><button type="button" wire:click="openHistoryOrder('{{ $order['number'] }}')" class="btn btn-outline mt-4 min-h-11 w-full" aria-label="{{ __('shop.history_open', ['number' => $order['number']]) }}">{{ __('shop.view_order') }}</button></div>
            </article>
        @empty
            <x-shop.empty :title="__('shop.history_empty')"><button type="button" wire:click="resetHistoryFilters" class="btn btn-outline min-h-11">{{ __('shop.history_reset') }}</button></x-shop.empty>
        @endforelse
    </div>
    <nav class="mt-6 flex flex-wrap items-center justify-center gap-4" aria-label="{{ __('shop.history_pages') }}"><button type="button" wire:click="historyPage({{ $history['page'] - 1 }})" class="btn btn-outline min-h-11" @disabled($history['page'] <= 1)>{{ __('shop.previous') }}</button><p>{{ __('shop.history_page', ['page' => $history['page'], 'pages' => $history['pages']]) }}</p><button type="button" wire:click="historyPage({{ $history['page'] + 1 }})" class="btn btn-outline min-h-11" @disabled($history['page'] >= $history['pages'])>{{ __('shop.next') }}</button></nav>
@endif
