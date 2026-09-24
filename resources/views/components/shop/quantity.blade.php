@props(['offer', 'error' => null])
<div>
    <label for="preview-quantity-{{ $offer['id'] }}" class="mb-2 block text-sm text-muted">{{ __('shop.quantity') }}</label>
    <input id="preview-quantity-{{ $offer['id'] }}" type="number" min="1" max="99" inputmode="numeric" aria-label="{{ __('shop.quantity') }} {{ $offer['article'] }}"
        @if ($error) aria-invalid="true" aria-describedby="preview-quantity-{{ $offer['id'] }}-error" @endif
        {{ $attributes->class(['input !w-24 min-h-11 !text-base', 'input-error' => (bool) $error]) }}>
    @if ($error)<p id="preview-quantity-{{ $offer['id'] }}-error" class="mt-2 text-sm text-warn-ink" role="alert">{{ $error }}</p>@endif
</div>
