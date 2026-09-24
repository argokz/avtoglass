@if ($this->canPrepareProposal())
    <section class="mt-6 rounded-xl border border-dashed border-line-strong p-4" data-manager-editor>
        <h2 id="manager-editor-title" tabindex="-1" class="font-bold">{{ __('shop.manager_editor_title') }}</h2><p class="mt-2 text-sm text-muted">{{ __('shop.manager_editor_note') }}</p>
        <button type="button" wire:click="openProposalEditor" class="btn btn-outline mt-3 min-h-11">{{ __('shop.manager_editor_open') }}</button>
        @if ($showManagerEditor && ($managerEditorContext['number'] ?? null) === $demoOrder['number'])
            <form wire:submit="sendEditedProposal" class="mt-5 space-y-5">
                @error('manager')<x-shop.notice tone="warning" role="alert">{{ $message }}</x-shop.notice>@enderror
                @foreach ($managerLines as $oldId => $line)
                    <fieldset class="min-w-0 rounded-xl border border-line bg-surface p-4" wire:key="manager-line-{{ $oldId }}"><legend class="px-2 text-sm font-bold">{{ $demoOrder['items'][$oldId]['article'] ?? $offers[$oldId]['article'] ?? '—' }}</legend>
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
                            <div><label for="manager-offer-{{ $oldId }}" class="mb-2 block text-sm font-bold">{{ __('shop.manager_offer') }}</label><select id="manager-offer-{{ $oldId }}" wire:model="managerLines.{{ $oldId }}.offer" class="select !text-base">@if (! isset($offers[$line['offer']]))<option value="{{ $line['offer'] }}">{{ __('shop.archived_glass') }}</option>@endif @foreach ($offers as $offer)<option value="{{ $offer['id'] }}">{{ $offer['article'] }} · {{ $offer['manufacturer'] }} · {{ __('shop.'.$offer['title']) }}</option>@endforeach</select></div>
                            <x-shop.field name="manager-quantity-{{ $oldId }}" :label="__('shop.quantity')" type="number" min="1" max="99" wire:model="managerLines.{{ $oldId }}.quantity" :error="$errors->first('managerLines.'.$oldId.'.quantity')" />
                        </div>
                        <label class="mt-3 flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="managerLines.{{ $oldId }}.remove" class="h-5 w-5 accent-brand">{{ __('shop.manager_remove_line') }}</label>
                    </fieldset>
                @endforeach
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label for="manager-fulfillment" class="mb-2 block text-sm font-bold">{{ __('shop.delivery') }}</label><select id="manager-fulfillment" wire:model.live="managerFulfillment" class="select !text-base">@foreach ($demoOrder['mode'] === 'wholesale' ? ['pickup', 'delivery'] : ['pickup', 'delivery', 'installation', 'visit'] as $method)<option value="{{ $method }}">{{ __('shop.'.($method === 'delivery' ? 'shipping' : $method)) }}</option>@endforeach</select></div>
                    @if (in_array($managerFulfillment, ['pickup', 'installation'], true))<div><label for="manager-point" class="mb-2 block text-sm font-bold">{{ __('shop.manager_point') }}</label><select id="manager-point" wire:model="managerPoint" class="select !text-base"><option value="demo-center">{{ __('shop.manager_demo_point') }}</option></select></div>@endif
                </div>
                @if (in_array($managerFulfillment, ['delivery', 'visit'], true))<x-shop.field name="manager-address" :label="__('shop.address')" wire:model="managerAddress" :error="$errors->first('managerAddress')" />@endif
                @if (in_array($managerFulfillment, ['installation', 'visit'], true))<div><label for="manager-slot" class="mb-2 block text-sm font-bold">{{ __('shop.manager_slot') }}</label><select id="manager-slot" wire:model="managerSlot" class="select !text-base" @error('managerSlot') aria-invalid="true" aria-describedby="manager-slot-error" @enderror><option value="">{{ __('shop.manager_slot_choose') }}</option>@foreach ($this->proposalSlotOptions() as $key => $slot)<option value="{{ $key }}">{{ \Carbon\CarbonImmutable::parse($slot['start'])->format('d.m.Y H:i') }}–{{ \Carbon\CarbonImmutable::parse($slot['end'])->format('H:i') }}</option>@endforeach</select><p class="mt-2 text-sm text-muted">{{ config('app.timezone') }} · {{ __('shop.manager_slot_note') }}</p>@error('managerSlot')<p id="manager-slot-error" class="mt-2 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror</div>@endif
                <label class="flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" wire:model="managerFitmentChecked" class="mt-1 h-5 w-5 shrink-0 accent-brand" @error('managerFitmentChecked') aria-invalid="true" aria-describedby="manager-fitment-error" @enderror><span>{{ __('shop.manager_fitment_checked') }}</span></label>
                @error('managerFitmentChecked')<p id="manager-fitment-error" class="text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
                <button type="submit" class="btn btn-primary min-h-11" wire:loading.attr="disabled">{{ __('shop.manager_editor_send') }}</button>
            </form>
        @endif
    </section>
@endif
