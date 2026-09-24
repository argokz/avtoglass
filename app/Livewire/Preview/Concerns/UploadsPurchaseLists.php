<?php

namespace App\Livewire\Preview\Concerns;

use App\Wholesale\Import\PurchaseFileReader;
use App\Wholesale\Import\PurchaseImportException;
use App\Wholesale\Import\PurchaseListExport;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

trait UploadsPurchaseLists
{
    use WithFileUploads;

    public $purchaseFile;

    #[Locked]
    public ?array $importFile = null;

    public function reviewFile(): void
    {
        $this->useLocale();
        abort_unless($this->mode === 'wholesale', 403);
        if ($this->importRows) {
            throw ValidationException::withMessages(['import' => __('shop.file_close_previous')]);
        }
        $this->validate(['purchaseFile' => ['required', 'file', 'max:20480']], ['purchaseFile.*' => __('shop.file_size')]);
        try {
            $result = (new PurchaseFileReader)->read($this->purchaseFile->getRealPath(), $this->purchaseFile->getClientOriginalExtension());
        } catch (PurchaseImportException $exception) {
            throw ValidationException::withMessages(['purchaseFile' => __('shop.'.$exception->reason)]);
        }
        $reviewRows = [];
        $corrections = [];
        foreach ($result['rows'] as $number => $input) {
            $reviewRows[$number] = $this->matchImportRow($input['article'], $input['quantity'], true, $input['manufacturer'], $input['comment']);
            if ($input['issue']) {
                $reviewRows[$number]['state'] = $input['issue'];
            }
            $corrections[$number] = array_intersect_key($input, array_flip(['article', 'quantity', 'manufacturer', 'comment']));
        }
        $this->list = '';
        $this->importHash = hash('sha256', '');
        $this->importLimitError = null;
        $this->importRows = $reviewRows;
        $this->importCorrections = $corrections;
        $this->importFile = ['name' => mb_substr($this->purchaseFile->getClientOriginalName(), 0, 150), 'sheet' => $result['sheet']];
        $this->showList = true;
        $this->purchaseFile->delete();
        $this->purchaseFile = null;
        $this->resetValidation();
        $this->saveDraft();
    }

    public function downloadPurchaseTemplate(string $format)
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $contents = (new PurchaseListExport)->template($format);
        $mime = $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(fn () => print ($contents), "purchase-template.$format", ['Content-Type' => $mime]);
    }

    public function downloadImportReport()
    {
        $this->useLocale();
        abort_unless($this->importRows !== [], 404);
        $rows = [['row', 'article', 'quantity', 'manufacturer', 'comment', 'status']];
        foreach ($this->importPreview()['rows'] as $number => $row) {
            $rows[] = [$number, $row['article'], $row['quantity'], $row['manufacturer'] ?? '', $row['comment'] ?? '', __('shop.import_'.$row['state'])];
        }
        $contents = (new PurchaseListExport)->csv($rows);

        return response()->streamDownload(fn () => print ($contents), 'purchase-review.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
