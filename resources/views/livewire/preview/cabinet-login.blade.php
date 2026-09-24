<section class="mx-auto max-w-xl rounded-card border border-line bg-surface p-6 sm:p-8" aria-labelledby="login-title">
    <h1 id="login-title" class="text-3xl font-extrabold">{{ __('shop.login_title') }}</h1>
    <p class="mt-4 text-muted">{{ __('shop.login_note') }}</p>
    <x-shop.notice class="mt-5">{{ __('shop.login_demo_note') }}</x-shop.notice>
    @if ($loginState === 'phone')
        <form wire:submit="requestLoginCode" class="mt-6 space-y-5">
            <x-shop.field name="login-phone" :label="__('shop.phone')" type="tel" wire:model="loginPhone" autocomplete="tel" :error="$errors->first('loginPhone')" />
            <button type="submit" class="btn btn-primary min-h-11 w-full" wire:loading.attr="disabled">{{ __('shop.login_send') }}</button>
        </form>
    @else
        <p class="mt-5 font-bold">{{ __('shop.login_sent', ['phone' => $loginMaskedPhone]) }}</p>
        <p class="mt-2 text-sm text-muted">{{ __('shop.login_validity') }}</p>
        <p class="mt-4 rounded-xl bg-sunken p-4 text-sm">{{ __('shop.login_demo_code') }}: <strong data-demo-code class="font-mono text-xl">{{ $demoSmsCode }}</strong></p>
        <form wire:submit="verifyLoginCode" class="mt-5 space-y-5">
            <x-shop.field name="login-code" :label="__('shop.login_code')" wire:model="loginCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" :error="$errors->first('loginCode')" />
            <button type="submit" class="btn btn-primary min-h-11 w-full" wire:loading.attr="disabled">{{ __('shop.login_verify') }}</button>
        </form>
        @error('loginPhone')<p role="alert" class="mt-3 text-sm text-warn-ink">{{ $message }}</p>@enderror
        <div class="mt-5" x-data="{ remaining: 60, timer: null, init() { const tick = () => this.remaining = Math.max(0, Math.ceil(this.$wire.resendAt - Date.now() / 1000)); tick(); this.timer = setInterval(tick, 1000); }, destroy() { clearInterval(this.timer); } }">
            <p x-show="remaining > 0" class="text-sm text-muted">{{ __('shop.login_resend_in') }} <span x-text="remaining"></span> {{ __('shop.seconds') }}</p>
            <fieldset x-bind:disabled="remaining > 0" class="mt-3 min-w-0"><button type="button" wire:click="requestLoginCode" class="btn btn-outline min-h-11" wire:loading.attr="disabled">{{ __('shop.login_resend') }}</button></fieldset>
        </div>
        <button type="button" wire:click="changeLoginPhone" class="mt-3 min-h-11 text-sm font-bold underline">{{ __('shop.login_change_phone') }}</button>
    @endif
    <button type="button" wire:click="go('checkout')" class="btn btn-outline mt-6 min-h-11 w-full">{{ __('shop.login_guest') }}</button>
    <details class="mt-6 rounded-xl border border-dashed border-line-strong p-4">
        <summary class="min-h-11 cursor-pointer py-3 text-sm font-bold">{{ __('shop.scenario') }}</summary>
        <div class="mt-3 flex flex-wrap gap-3"><button type="button" wire:click="simulateLoginError('delivery')" class="btn btn-outline min-h-11">{{ __('shop.login_demo_delivery') }}</button>@if ($loginState === 'code')<button type="button" wire:click="simulateLoginError('expired')" class="btn btn-outline min-h-11">{{ __('shop.login_demo_expired') }}</button>@endif</div>
    </details>
</section>
