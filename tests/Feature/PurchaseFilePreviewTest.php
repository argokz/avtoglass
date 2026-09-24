<?php

namespace Tests\Feature;

use App\Livewire\Preview\PurchaseJourney;
use App\Wholesale\Import\PurchaseListExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseFilePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function preview()
    {
        $this->app->instance('env', 'preview');

        return Livewire::test(PurchaseJourney::class);
    }

    public function test_uploaded_csv_respects_manufacturer_and_requires_explicit_application(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('purchase.csv', "article;quantity;manufacturer;comment\nDEMO-CAMRY;2;FYG;test\nUNKNOWN;1;;check");
        $component = $this->preview()->call('go', 'wholesale')->set('purchaseFile', $file);
        $this->assertTrue($component->instance()->hasPendingImport());
        $component->call('reviewFile')
            ->assertHasNoErrors()->assertSet('cart', [])->assertSet('importRows.2.offerId', 4)
            ->assertSet('importRows.3.state', 'not_found')->assertSet('purchaseFile', null);
        $component->call('applyImport')->assertSet('cart', [4 => 2])->call('downloadImportReport')->assertFileDownloaded('purchase-review.csv');
        $this->preview()->assertSet('importFile.name', 'purchase.csv')->assertSet('importRows.2.state', 'applied');
    }

    public function test_xlsx_template_upload_and_download_are_available(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('purchase.xlsx', (new PurchaseListExport)->template('xlsx'));
        $this->preview()->call('go', 'wholesale')->set('purchaseFile', $file)->call('reviewFile')
            ->assertHasNoErrors()->assertSet('importFile.sheet', 'Заказ')->assertSet('cart', [])
            ->call('downloadPurchaseTemplate', 'xlsx')->assertFileDownloaded('purchase-template.xlsx')
            ->call('downloadPurchaseTemplate', 'csv')->assertFileDownloaded('purchase-template.csv');
    }

    public function test_bad_file_keeps_selection_and_upload_does_not_replace_existing_review(): void
    {
        Storage::fake('local');
        $component = $this->preview()->call('go', 'wholesale')->call('toggle', 1)
            ->set('purchaseFile', UploadedFile::fake()->createWithContent('bad.xls', 'bad'))->call('reviewFile')
            ->assertHasErrors('purchaseFile')->assertSet('cart', [1 => 1]);
        $this->assertTrue($component->instance()->hasPendingImport());
        $component->call('clearList');
        $this->assertFalse($component->instance()->hasPendingImport());
        $component->set('list', 'DEMO-2;1')->call('matchList')
            ->set('purchaseFile', UploadedFile::fake()->createWithContent('new.csv', "article;quantity\nDEMO-4;3"))
            ->call('reviewFile')->assertHasErrors('import')->assertSet('importRows.1.offerId', 2);
    }

    public function test_missing_repeat_rows_are_preserved_and_block_submission(): void
    {
        $component = $this->preview()->call('loadRepeatExample')->call('repeatOrder')
            ->assertSet('cart', [1 => 1, 3 => 1])->assertSet('unresolvedRows.99.quantity', 2)
            ->assertSet('demoOrder.cart.99', 2)->assertSee('Позиции требуют решения');
        $this->assertNull($component->instance()->quote()['total']);
        $component->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true)->set('accepted', true)
            ->call('submit')->assertHasErrors('checkout')->assertSet('demoOrder.total', 14000000);
        $component->call('go', 'wholesale')->assertSet('unresolvedRows', [])
            ->call('go', 'catalog')->assertSet('unresolvedRows.99.quantity', 2);
        $this->preview()->assertSet('unresolvedRows.99.quantity', 2)->assertSet('repeatedFrom', 'DEMO-2090');
    }

    public function test_explicit_replacement_keeps_original_order_and_requires_review(): void
    {
        $component = $this->preview()->call('loadRepeatExample')->call('repeatOrder')->call('toggle', 3)
            ->call('replaceMissingItem', 99, 4)->assertSet('cart', [1 => 1, 4 => 2])
            ->assertSet('unresolvedRows', [])->assertSet('demoOrder.cart.99', 2);
        $this->assertTrue($component->instance()->requiresReview());
        $component->set('name', 'Демо клиент')->set('phone', '+77000000001')->set('consent', true)->set('accepted', true)
            ->call('submit')->assertHasNoErrors()->assertSet('demoOrder.status', 'pending')
            ->assertSet('demoOrder.repeated_from', 'DEMO-2090');
    }

    public function test_replacement_never_truncates_combined_quantity(): void
    {
        $this->preview()->call('loadRepeatExample')->call('repeatOrder')->call('toggle', 4)->set('cart.4', 98)
            ->call('replaceMissingItem', 99, 4)->assertHasErrors('missing')->assertSet('cart.4', 98)
            ->assertSet('unresolvedRows.99.quantity', 2);
    }

    public function test_archived_draft_restores_unresolved_rows_and_all_missing_selection_stays_visible(): void
    {
        $this->preview()->call('loadRepeatExample')->call('repeatOrder')->call('toggle', 1)->call('toggle', 3)
            ->assertSet('cart', [])->assertSee('Позиции требуют решения')->call('go', 'drafts')
            ->assertSee('Розница')->call('repeatOrder')->assertSet('savedDrafts.0.details.unresolved.99.quantity', 2)
            ->call('resumeDraft', 0)->assertSet('cart', [])->assertSet('unresolvedRows.99.quantity', 2)
            ->call('excludeMissingItem', 99)->assertSet('unresolvedRows', [])->assertSee('Пока ничего не выбрано');
    }
}
