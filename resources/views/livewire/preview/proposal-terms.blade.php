<div class="min-w-0">
    <ul class="divide-y divide-line">
        @foreach ($terms['cart'] as $id => $quantity)
            @php $item = $terms['items'][$id] ?? $offers[$id] ?? ['title' => 'archived_glass', 'article' => '—', 'manufacturer' => '—']; @endphp
            <li class="py-3"><p class="break-words font-bold">{{ __('shop.'.$item['title']) }} · {{ $item['manufacturer'] }}</p><p class="mt-1 text-sm text-muted">{{ $item['article'] }}</p><p class="mt-2 text-sm">{{ __('shop.quantity') }}: {{ $quantity }} · {{ __('shop.unit_price') }}: <x-shop.price :amount="$terms['prices'][$id] ?? null" /></p></li>
        @endforeach
    </ul>
    @include('livewire.preview.receipt-snapshot', ['receipt' => $terms])
</div>
