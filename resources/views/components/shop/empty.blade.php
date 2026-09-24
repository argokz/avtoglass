@props(['title' => null, 'description' => null])
<div {{ $attributes->class(['rounded-card border border-dashed border-line-strong bg-surface px-6 py-12 text-center']) }}>
    <div class="mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-sunken text-muted"><x-glass-icon type="windshield" class="h-8 w-8" /></div>
    <h2 class="text-xl font-extrabold">{{ $title ?? __('shop.empty_title') }}</h2>
    <p class="mx-auto mt-2 max-w-md text-muted">{{ $description ?? __('shop.empty_note') }}</p>
    <div class="mt-6 flex flex-wrap justify-center gap-3">{{ $slot }}</div>
</div>
