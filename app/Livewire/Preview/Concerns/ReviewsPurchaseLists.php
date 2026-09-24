<?php

namespace App\Livewire\Preview\Concerns;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UX fixture workflow; production imports will use the shared draft service. */
trait ReviewsPurchaseLists
{
    #[Locked]
    public array $importRows = [];

    #[Locked]
    public ?string $importHash = null;

    #[Locked]
    public ?string $importLimitError = null;

    public array $importCorrections = [];

    public function matchList(): void
    {
        $this->useLocale();
        $this->resetValidation('import');
        if ($this->importFile) {
            throw ValidationException::withMessages(['import' => __('shop.file_close_previous')]);
        }
        if ($this->importRows && array_filter($this->importRows, fn ($row) => $row['state'] === 'applied')) {
            throw ValidationException::withMessages(['import' => __('shop.import_finish_first')]);
        }
        $this->importRows = [];
        $this->importCorrections = [];
        $this->importLimitError = null;
        $this->importHash = hash('sha256', $this->list);
        $lines = preg_split('/\R/u', $this->list) ?: [];
        if (strlen($this->list) > 200000 || count(array_filter($lines, fn ($line) => trim($line) !== '')) > 1000) {
            $this->importLimitError = 'import_too_large';
            $this->saveDraft();

            return;
        }
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = preg_split('/[;,\t]/', trim($line));
            $article = strtoupper(trim($parts[0] ?? ''));
            $quantity = trim($parts[1] ?? '');
            $this->importRows[$index + 1] = $this->matchImportRow($article, $quantity, count($parts) === 2);
            $this->importCorrections[$index + 1] = ['article' => mb_substr($article, 0, 100), 'quantity' => mb_substr($quantity, 0, 30), 'manufacturer' => '', 'comment' => ''];
        }
        $this->saveDraft();
    }

    private function matchImportRow(string $article, string $quantity, bool $validFormat = true, string $manufacturer = '', string $comment = ''): array
    {
        $article = strtoupper(trim($article));
        $quantity = trim($quantity);
        $candidates = [];
        foreach ($this->offers() as $offer) {
            if (($offer['article'] === $article || ($offer['code'] ?? null) === $article)
                && ($manufacturer === '' || mb_strtolower(trim($manufacturer)) === mb_strtolower($offer['manufacturer']))) {
                $candidates[] = $offer['id'];
            }
        }
        $state = match (true) {
            ! $validFormat => 'format',
            ! $this->validQuantity($quantity) => 'quantity',
            count($candidates) === 0 => 'not_found',
            count($candidates) > 1 => 'choice',
            default => 'found',
        };

        return [
            'article' => mb_substr($article, 0, 100), 'quantity' => mb_substr($quantity, 0, 30),
            'candidates' => $candidates, 'offerId' => count($candidates) === 1 ? $candidates[0] : null, 'state' => $state,
            'manufacturer' => $manufacturer, 'comment' => $comment,
        ];
    }

    public function correctImportRow(int $rowNumber): void
    {
        abort_unless(isset($this->importRows[$rowNumber]) && ! in_array($this->importRows[$rowNumber]['state'], ['applied', 'skipped'], true), 422);
        $this->useLocale();
        $this->validate([
            "importCorrections.$rowNumber.article" => ['required', 'string', 'max:100'],
            "importCorrections.$rowNumber.quantity" => ['required', 'string', 'max:30'],
            "importCorrections.$rowNumber.manufacturer" => ['nullable', 'string', 'max:100'],
            "importCorrections.$rowNumber.comment" => ['nullable', 'string', 'max:1000'],
        ], ['importCorrections.*.*.*' => __('shop.import_correct_error')]);
        $input = $this->importCorrections[$rowNumber];
        $this->importRows[$rowNumber] = $this->matchImportRow($input['article'], $input['quantity'], true, $input['manufacturer'] ?? '', $input['comment'] ?? '');
        $this->saveDraft();
    }

    public function chooseImportOffer(int $rowNumber, int $offerId): void
    {
        $row = $this->importRows[$rowNumber] ?? null;
        abort_unless($row && $row['state'] === 'choice' && in_array($offerId, $row['candidates'], true), 422);
        $this->importRows[$rowNumber]['offerId'] = $offerId;
        $this->importRows[$rowNumber]['state'] = 'found';
        $this->saveDraft();
    }

    public function skipImportRow(int $rowNumber): void
    {
        abort_unless(isset($this->importRows[$rowNumber]) && $this->importRows[$rowNumber]['state'] !== 'applied', 422);
        $this->importRows[$rowNumber]['state'] = 'skipped';
        $this->saveDraft();
    }

    public function importPreview(): array
    {
        $rows = $this->importRows;
        $groups = [];
        foreach ($rows as $number => $row) {
            if ($row['state'] === 'found') {
                $id = $row['offerId'];
                $groups[$id] ??= ['quantity' => 0, 'rows' => []];
                $groups[$id]['quantity'] += (int) $row['quantity'];
                $groups[$id]['rows'][] = $number;
            }
        }
        $readyRows = 0;
        foreach ($groups as $id => &$group) {
            $existing = $this->cart[$id] ?? 0;
            $group['total'] = is_numeric($existing) ? (int) $existing + $group['quantity'] : 100;
            $group['valid'] = $this->validQuantity($group['total']);
            foreach ($group['rows'] as $number) {
                $rows[$number]['combined'] = $group['total'];
                $rows[$number]['state'] = $group['valid'] ? 'found' : 'overflow';
            }
            if ($group['valid']) {
                $readyRows += count($group['rows']);
            }
        }
        unset($group);
        $pending = count(array_filter($rows, fn ($row) => ! in_array($row['state'], ['applied', 'skipped'], true)));

        return ['rows' => $rows, 'groups' => $groups, 'ready' => $readyRows, 'remaining' => $pending - $readyRows];
    }

    public function applyImport(): void
    {
        $this->useLocale();
        $this->resetValidation('import');
        if ($this->mode !== 'wholesale' || $this->importLimitError || $this->importHash !== hash('sha256', $this->list)) {
            throw ValidationException::withMessages(['import' => __('shop.import_recheck')]);
        }
        if ($this->scenario === 'save_error' || $this->invalidQuantities()) {
            throw ValidationException::withMessages(['import' => __('shop.import_save_first')]);
        }
        $preview = $this->importPreview();
        $newIds = array_filter(array_keys($preview['groups']), fn ($id) => $preview['groups'][$id]['valid']);
        if (count(array_unique([...array_keys($this->cart), ...$newIds])) > 500) {
            throw ValidationException::withMessages(['import' => __('shop.file_positions')]);
        }
        foreach ($preview['groups'] as $id => $group) {
            if (! $group['valid']) {
                continue;
            }
            $this->cart[$id] = $group['total'];
            foreach ($group['rows'] as $number) {
                $this->importRows[$number]['state'] = 'applied';
            }
        }
        $this->notice = $preview['ready'] > 0 ? 'import_applied_note' : '';
        $this->saveDraft();
    }

    public function hasPendingImport(): bool
    {
        return $this->mode === 'wholesale' && ($this->purchaseFile !== null || $this->importLimitError !== null
            || ($this->list !== '' && $this->importHash !== hash('sha256', $this->list))
            || count(array_filter($this->importRows, fn ($row) => ! in_array($row['state'], ['applied', 'skipped'], true))) > 0);
    }

    public function clearList(): void
    {
        $this->list = '';
        $this->importRows = [];
        $this->importHash = null;
        $this->importLimitError = null;
        $this->importCorrections = [];
        $this->importFile = null;
        if ($this->purchaseFile) {
            $this->purchaseFile->delete();
            $this->purchaseFile = null;
        }
        $this->showList = false;
        $this->resetValidation('import');
        $this->saveDraft();
    }
}
