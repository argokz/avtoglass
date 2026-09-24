@props(['name', 'label', 'type' => 'text', 'error' => null, 'hint' => null])
<div>
    <label for="{{ $name }}" class="mb-2 block text-sm font-bold">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           @if ($error) aria-invalid="true" @endif
           @if ($error || $hint) aria-describedby="{{ $name }}-description" @endif
           {{ $attributes->class(['input min-h-11 !text-base', 'input-error' => (bool) $error]) }}>
    @if ($error || $hint)
        <p id="{{ $name }}-description" @class(['mt-2 text-sm', 'text-warn-ink' => $error, 'text-muted' => ! $error])>{{ $error ?: $hint }}</p>
    @endif
</div>
