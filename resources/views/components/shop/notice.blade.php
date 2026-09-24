@props(['tone' => 'info', 'title' => null])
<div {{ $attributes->class([
    'rounded-2xl border px-4 py-4 text-sm leading-relaxed',
    'border-warn/30 bg-warn-soft text-warn-ink' => $tone === 'warning',
    'border-ok/30 bg-ok-soft text-ok-ink' => $tone === 'success',
    'border-line bg-sunken text-ink' => $tone === 'info',
]) }}>
    @if ($title)<p class="mb-1 font-bold">{{ $title }}</p>@endif
    {{ $slot }}
</div>
