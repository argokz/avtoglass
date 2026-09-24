<section class="mt-5 rounded-card border border-line bg-surface p-5" aria-labelledby="missing-title">
    <h2 id="missing-title" class="text-lg font-extrabold">{{ __('shop.repeat_missing_title') }}</h2>
    <p class="mt-2 text-sm text-muted">{{ __('shop.repeat_resolve') }}</p>
    @error('missing')<p class="mt-3 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
    <ul class="mt-4 space-y-4">
        @foreach ($unresolvedRows as $id => $row)
            <li class="rounded-xl bg-sunken p-4" wire:key="missing-{{ $id }}" data-missing-row="{{ $id }}">
                <h3 class="font-bold">{{ __('shop.'.$row['title']) }}</h3>
                <p class="mt-2 text-sm">{{ $row['manufacturer'] }} · {{ $row['article'] }} · {{ __('shop.quantity') }}: {{ $row['quantity'] }}</p>
                <p class="mt-2 text-sm text-muted">{{ __('shop.repeat_historical_price') }}: <x-shop.price :amount="$row['oldPrice']" /></p>
                <div class="mt-4 flex flex-wrap items-start gap-3">
                    <button type="button" wire:click="excludeMissingItem({{ $id }})" class="btn btn-outline min-h-11">{{ __('shop.repeat_exclude') }}</button>
                    <details class="w-full rounded-xl border border-line bg-surface p-3">
                        <summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">{{ __('shop.repeat_choose_replacement') }}</summary>
                        <p class="mt-2 text-sm text-muted">{{ __('shop.repeat_replacement_note') }}</p>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            @foreach ($offers as $offer)
                                <button type="button" wire:click="replaceMissingItem({{ $id }}, {{ $offer['id'] }})" class="min-h-11 rounded-xl border border-line px-4 py-3 text-left hover:border-brand" aria-label="{{ __('shop.repeat_replace_with', ['article' => $offer['article']]) }}">
                                    <span class="block text-sm font-bold">{{ __('shop.'.$offer['title']) }}</span>
                                    <span class="mt-2 block text-sm">{{ $offer['manufacturer'] }} · {{ $offer['article'] }} · <x-shop.price :amount="$offer['price']" /></span>
                                    <span class="mt-2 block"><x-shop.status :state="$offer['stock'] >= $row['quantity'] ? 'available' : 'unavailable'" /></span>
                                </button>
                            @endforeach
                        </div>
                    </details>
                </div>
            </li>
        @endforeach
    </ul>
</section>
