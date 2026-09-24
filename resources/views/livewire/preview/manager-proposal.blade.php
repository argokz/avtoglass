@if ($proposal)
    @php
        $blocker = $this->proposalBlocker();
        $proposalBadge = match ($proposal['status']) {
            'accepted' => 'confirmed',
            'open' => $blocker === 'proposal_expired' ? 'expired' : 'changed',
            default => $proposal['status'],
        };
    @endphp
    <section class="mt-6 rounded-card border border-line bg-surface p-5 sm:p-6" aria-labelledby="proposal-title" data-manager-proposal wire:key="manager-proposal-{{ $proposal['version'] }}">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm font-bold text-brand-ink">{{ __('shop.proposal_version', ['version' => $proposal['version']]) }}</p><h2 id="proposal-title" tabindex="-1" class="mt-2 text-2xl font-extrabold">{{ __('shop.proposal_title') }}</h2></div><x-shop.status :state="$proposalBadge" /></div>
        <p class="mt-3 text-muted">{{ __('shop.proposal_explanation') }}</p>
        <p class="mt-2 text-sm">{{ __('shop.proposal_expires') }}: {{ \Carbon\CarbonImmutable::parse($proposal['expiresAt'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }} ({{ config('app.timezone') }})</p>
        <div class="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
            <div class="min-w-0">
                <div class="grid gap-3 sm:grid-cols-2"><div class="min-w-0 rounded-xl bg-sunken p-4" data-proposal-before><p class="text-sm text-muted">{{ __('shop.proposal_before') }}</p><x-shop.price :amount="$proposal['previousTotal']" class="mt-2 text-xl" />@include('livewire.preview.proposal-terms', ['terms' => $proposal['before'] ?? $demoOrder])</div><div class="min-w-0 rounded-xl border border-brand p-4" data-proposal-after><p class="text-sm text-muted">{{ __('shop.proposal_after') }}</p><x-shop.price :amount="$proposal['total']" class="mt-2 text-xl" />@include('livewire.preview.proposal-terms', ['terms' => $proposal])</div></div>
                <p class="mt-3 text-sm">{{ __('shop.services') }}: <x-shop.price :amount="$proposal['services']" /> · {{ __('shop.shipping_fee') }}: <x-shop.price :amount="$proposal['delivery']" /></p>
                <p class="mt-3 text-sm text-muted">{{ __('shop.proposal_fitment') }}</p>
                @if ($proposal['total'] !== null)
                    @php $impact = $this->settlement($proposal['total']); @endphp
                    <div class="mt-4 rounded-xl bg-sunken p-4 text-sm" data-proposal-settlement><p class="font-bold">{{ __('shop.settlement_after_accept') }}</p><p class="mt-2">{{ __('shop.settlement_net') }}: <x-shop.price :amount="$impact['net']" /></p><p class="mt-2">{{ __('shop.settlement_due') }}: <x-shop.price :amount="$impact['due']" /></p><p class="mt-2">{{ __('shop.settlement_refund_due') }}: <x-shop.price :amount="$impact['refund']" /></p><p class="mt-3 text-muted">{{ __('shop.settlement_explanation') }}</p></div>
                @endif
                @if ($blocker)
                    <x-shop.notice :tone="$proposal['status'] === 'accepted' ? 'success' : 'warning'" class="mt-4">{{ __('shop.'.$blocker) }}</x-shop.notice>
                @endif
                @error('proposal')<p class="mt-4 font-bold text-warn-ink" role="alert">{{ $message }}</p><button type="button" wire:click="loadLatestProposal" class="btn btn-outline mt-3 min-h-11">{{ __('shop.proposal_load_latest') }}</button>@enderror
                @if ($proposal['status'] === 'accepted')<div class="mt-4 rounded-xl bg-ok-soft p-4 text-ok-ink"><h3 class="font-bold">{{ __('shop.proposal_payment_ready') }}</h3><p class="mt-2 text-sm">{{ __('shop.proposal_payment_demo') }}</p></div>@endif
            </div>
            <div>
                @if ($proposal['status'] === 'open')
                    <form wire:submit="acceptProposal({{ $proposal['version'] }})">
                        <label class="flex min-h-11 items-start gap-3 text-sm leading-relaxed"><input type="checkbox" wire:model="proposalConsent" class="mt-1 h-5 w-5 shrink-0 accent-brand" @disabled($blocker) @error('proposalConsent') aria-invalid="true" aria-describedby="proposal-consent-error" @enderror><span>{{ __('shop.proposal_consent') }}</span></label>
                        @error('proposalConsent')<p id="proposal-consent-error" class="mt-2 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" class="btn btn-primary mt-4 min-h-12 w-full" @disabled($blocker) wire:loading.attr="disabled">{{ __('shop.proposal_accept') }}</button>
                    </form>
                    <form wire:submit="declineProposal({{ $proposal['version'] }})" class="mt-5 border-t border-line pt-5">
                        <label for="proposal-reply" class="mb-2 block text-sm font-bold">{{ __('shop.proposal_reply_label') }}</label><textarea id="proposal-reply" wire:model="proposalReply" rows="3" class="input !text-base" @error('proposalReply') aria-invalid="true" aria-describedby="proposal-reply-error" @enderror></textarea>
                        @error('proposalReply')<p id="proposal-reply-error" class="mt-2 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
                        <button type="submit" class="btn btn-outline mt-3 min-h-11 w-full" wire:loading.attr="disabled">{{ __('shop.proposal_decline') }}</button>
                    </form>
                @endif
                @if ($proposal['status'] !== 'accepted' && $proposal['status'] !== 'refresh_requested')<button type="button" wire:click="requestFreshProposal" class="btn btn-outline mt-4 min-h-11 w-full">{{ __('shop.proposal_refresh') }}</button>@endif
            </div>
        </div>
    </section>
@endif
@if (array_diff_key($demoOrder['cart'], $offers) === [] && $this->canPrepareProposal())
    <section class="mt-6 rounded-xl border border-dashed border-line-strong p-4" aria-label="{{ __('shop.proposal_demo') }}"><h2 class="text-sm font-bold text-muted">{{ __('shop.proposal_demo') }}</h2><div class="mt-3 flex flex-wrap gap-2">@foreach (['ready', 'waiting', 'expired', 'stock_changed', 'conflict'] as $variant)<button type="button" wire:click="prepareProposal('{{ $variant }}')" class="btn btn-outline min-h-11">{{ __('shop.proposal_demo_'.$variant) }}</button>@endforeach</div></section>
@endif
@if ($proposal && $proposal['status'] === 'open' && ($proposal['slot'] ?? null))<button type="button" wire:click="simulateProposalSlotLoss({{ $proposal['version'] }})" class="btn btn-outline mt-4 min-h-11">{{ __('shop.manager_demo_slot_loss') }}</button>@endif
