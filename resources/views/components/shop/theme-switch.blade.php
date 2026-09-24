<label {{ $attributes->class(['flex items-center gap-2 text-sm font-semibold']) }}>
    <span>{{ __('shop.theme') }}</span>
    <select disabled aria-label="{{ __('shop.theme') }}" class="select !w-auto !min-h-11 !rounded-pill !py-2" data-theme-select>
        <option value="system">{{ __('shop.theme_system') }}</option>
        <option value="light">{{ __('shop.theme_light') }}</option>
        <option value="dark">{{ __('shop.theme_dark') }}</option>
    </select>
</label>
