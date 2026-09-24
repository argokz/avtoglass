@php $importPreview = $this->importPreview(); @endphp
<section class="mt-5 min-w-0 rounded-card border border-line bg-surface p-5" aria-labelledby="import-title">
    <h2 id="import-title" class="text-lg font-extrabold">{{ __('shop.import_title') }}</h2>
    <p class="mt-2 text-sm text-muted">{{ __('shop.import_hint') }}</p>
    <div class="mt-5 rounded-xl bg-sunken p-4" x-data="{ uploading: false, progress: 0, uploadFailed: false }" x-on:livewire-upload-start="uploading = true; uploadFailed = false" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false; uploadFailed = true" x-on:livewire-upload-cancel="uploading = false; uploadFailed = true" x-on:livewire-upload-progress="progress = $event.detail.progress">
        <div class="flex flex-wrap gap-3"><button type="button" wire:click="downloadPurchaseTemplate('xlsx')" class="btn btn-outline min-h-11">{{ __('shop.file_template_xlsx') }}</button><button type="button" wire:click="downloadPurchaseTemplate('csv')" class="btn btn-outline min-h-11">{{ __('shop.file_template_csv') }}</button></div>
        <label for="purchase-file" class="mt-4 mb-2 block text-sm font-bold">{{ __('shop.file_label') }}</label>
        <input id="purchase-file" type="file" accept=".csv,.xlsx" wire:model="purchaseFile" x-on:change="uploading = $el.files.length > 0; progress = 0; uploadFailed = false" class="block w-full min-w-0 text-sm file:mr-3 file:min-h-11 file:rounded-pill file:border-0 file:bg-surface file:px-4 file:text-ink" @disabled($importRows !== [])>
        <p class="mt-3 text-sm text-muted">{{ __('shop.file_hint') }}</p>
        <p x-show="uploading" x-cloak class="mt-3 text-sm" role="status">{{ __('shop.file_uploading') }} <span x-text="progress"></span>%</p>
        <p x-show="uploadFailed" x-cloak class="mt-3 text-sm text-warn-ink" role="alert">{{ __('shop.file_upload_failed') }}</p>
        @error('purchaseFile')<p class="mt-3 text-sm text-warn-ink" role="alert">{{ $message }}</p>@enderror
        <fieldset x-bind:disabled="uploading || uploadFailed" class="min-w-0">
            <button type="button" wire:click="reviewFile" class="btn btn-primary mt-4 min-h-11" @disabled(! $purchaseFile || $importRows !== []) wire:loading.attr="disabled">{{ __('shop.file_review') }}</button>
        </fieldset>
    </div>
    @if ($importFile)
        <p class="mt-4 break-words font-bold">{{ __('shop.file_loaded') }}: {{ $importFile['name'] }}</p>
        @if ($importFile['sheet'])<p class="mt-2 text-sm text-muted">{{ __('shop.file_first_sheet') }}: {{ $importFile['sheet'] }}</p>@endif
    @else
        <label for="preview-list" class="mt-5 mb-2 block font-bold">{{ __('shop.paste_hint') }}</label>
        <textarea id="preview-list" wire:model="list" rows="4" class="input font-mono !text-base" placeholder="DEMO-1;2&#10;DEMO-CAMRY;1" @disabled(array_filter($importRows, fn ($row) => $row['state'] === 'applied'))></textarea>
    @endif
    <div class="mt-4 flex flex-wrap gap-3">
        @if (! $importFile)<button type="button" wire:click="matchList" class="btn btn-primary min-h-11">{{ __('shop.match') }}</button>@endif
        <button type="button" wire:click="clearList" class="btn btn-outline min-h-11">{{ __('shop.clear_import') }}</button>
        @if ($importRows)<button type="button" wire:click="downloadImportReport" class="btn btn-outline min-h-11">{{ __('shop.file_report') }}</button>@endif
    </div>
    <p class="mt-3 text-sm text-muted">{{ __('shop.import_clear_note') }}</p>
    @error('import')<x-shop.notice tone="warning" role="alert" class="mt-4">{{ $message }}</x-shop.notice>@enderror
    @if ($importLimitError)<x-shop.notice tone="warning" role="alert" class="mt-4">{{ __('shop.'.$importLimitError) }}</x-shop.notice>@endif
    @if ($importPreview['rows'])
        @if ($importPreview['remaining'])<x-shop.notice tone="warning" class="mt-4" :title="__('shop.import_errors')">{{ __('shop.import_remaining', ['count' => $importPreview['remaining']]) }}</x-shop.notice>@endif
        <ol class="mt-5 space-y-3">
            @foreach ($importPreview['rows'] as $number => $row)
                <li class="min-w-0 rounded-xl border border-line p-4" wire:key="import-{{ $importHash }}-{{ $number }}" data-import-row="{{ $number }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0"><h3 class="font-bold">{{ __('shop.import_row', ['row' => $number]) }} · <span class="break-all">{{ $row['article'] }}</span></h3><p class="mt-1 text-sm">{{ __('shop.quantity') }}: <span class="break-all">{{ $row['quantity'] }}</span></p></div>
                        <span @class(['text-sm font-bold', 'text-ok-ink' => in_array($row['state'], ['found', 'applied'], true), 'text-warn-ink' => ! in_array($row['state'], ['found', 'applied'], true)])>{{ __('shop.import_'.$row['state']) }}</span>
                    </div>
                    @if ($row['offerId'])<p class="mt-2 text-sm text-muted">{{ $offers[$row['offerId']]['manufacturer'] }} · {{ $offers[$row['offerId']]['article'] }}</p>@endif
                    @if ($row['comment'] ?? '')<p class="mt-2 break-words text-sm text-muted">{{ $row['comment'] }}</p>@endif
                    @if (isset($row['combined']))<p class="mt-2 text-sm">{{ __('shop.import_combined', ['count' => $row['combined']]) }}</p>@endif
                    @if ($row['state'] === 'choice')
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($row['candidates'] as $id)<button type="button" wire:click="chooseImportOffer({{ $number }}, {{ $id }})" class="btn btn-outline min-h-11" aria-label="{{ __('shop.import_choose', ['row' => $number, 'manufacturer' => $offers[$id]['manufacturer']]) }}">{{ $offers[$id]['manufacturer'] }} · {{ $offers[$id]['article'] }} · <x-shop.price :amount="$offers[$id]['price']" /></button>@endforeach
                        </div>
                    @endif
                    @if (in_array($row['state'], ['not_found', 'quantity', 'format', 'overflow', 'formula'], true))
                        <form wire:submit="correctImportRow({{ $number }})" class="mt-4 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto]">
                            <x-shop.field name="import-article-{{ $number }}" :label="__('shop.article')" wire:model="importCorrections.{{ $number }}.article" :error="$errors->first('importCorrections.'.$number.'.article')" />
                            <x-shop.field name="import-quantity-{{ $number }}" :label="__('shop.quantity')" wire:model="importCorrections.{{ $number }}.quantity" :error="$errors->first('importCorrections.'.$number.'.quantity')" inputmode="numeric" />
                            <button type="submit" class="btn btn-outline min-h-11">{{ __('shop.import_correct') }}</button>
                            <x-shop.field name="import-manufacturer-{{ $number }}" :label="__('shop.manufacturer')" wire:model="importCorrections.{{ $number }}.manufacturer" :error="$errors->first('importCorrections.'.$number.'.manufacturer')" />
                            <div class="sm:col-span-2"><x-shop.field name="import-comment-{{ $number }}" :label="__('shop.file_comment')" wire:model="importCorrections.{{ $number }}.comment" :error="$errors->first('importCorrections.'.$number.'.comment')" /></div>
                        </form>
                    @endif
                    @if (! in_array($row['state'], ['applied', 'skipped'], true))<button type="button" wire:click="skipImportRow({{ $number }})" class="mt-2 min-h-11 text-sm font-semibold underline underline-offset-4" aria-label="{{ __('shop.import_skip_row', ['row' => $number]) }}">{{ __('shop.import_skip') }}</button>@endif
                </li>
            @endforeach
        </ol>
        <div class="mt-5 rounded-xl bg-sunken p-4">
            <p class="text-sm">{{ __('shop.import_apply_summary', ['ready' => $importPreview['ready'], 'remaining' => $importPreview['remaining']]) }}</p>
            <button type="button" wire:click="applyImport" class="btn btn-primary mt-4 min-h-11" @disabled($importPreview['ready'] === 0 || $importLimitError) wire:loading.attr="disabled" wire:target="applyImport">{{ __('shop.import_apply', ['count' => $importPreview['ready']]) }}</button>
        </div>
    @endif
</section>
