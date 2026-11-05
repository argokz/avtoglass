<?php

namespace Tests\Feature;

use App\Catalog\PriceImportPreview;
use App\Console\Commands\ReviewPriceList;
use App\Filament\Pages\ImportPrices;
use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use Tests\TestCase;

/** «Что изменится» до сборки каталога: те же правила, что у catalog:build. */
class PriceImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private int $rowNo = 0;

    private function run_(array $rows): PriceImportRun
    {
        $run = PriceImportRun::query()->create(['filename' => 'prais.xlsx', 'status' => 'parsed', 'rows_total' => count($rows)]);
        foreach ($rows as $row) {
            PriceImportRow::query()->create([
                'price_import_run_id' => $run->id, 'row_no' => ++$this->rowNo, 'status' => 'parsed',
                'raw_name' => 'Лобовое '.$row['model'], 'raw_article' => $row['article'] ?? 'A-'.$row['model'],
                'dialect' => 'agc', 'brand_name' => 'Toyota', 'model_name' => $row['model'],
                'glass_type' => 'windshield', 'price' => $row['price'], 'options' => [],
            ]);
        }

        return $run;
    }

    private function собрать(PriceImportRun $run): void
    {
        $this->assertSame(0, Artisan::call('catalog:build', ['run' => $run->id]));
    }

    public function test_новый_прайс_до_сборки_показывает_новые_карточки(): void
    {
        $preview = app(PriceImportPreview::class)->for($this->run_([['model' => 'Camry', 'price' => 30000], ['model' => 'Corolla', 'price' => 25000]]));

        $this->assertSame(2, $preview['products_new']);
        $this->assertSame(2, $preview['offers_new']);
    }

    public function test_собранный_прайс_повторно_ничего_не_меняет(): void
    {
        $run = $this->run_([['model' => 'Camry', 'price' => 30000]]);
        $this->собрать($run);

        $preview = app(PriceImportPreview::class)->for($run);

        $this->assertSame(0, $preview['products_new']);
        $this->assertSame(1, $preview['price_same']);
    }

    public function test_рост_цены_и_подозрительный_скачок(): void
    {
        $this->собрать($this->run_([['model' => 'Camry', 'price' => 30000], ['model' => 'Corolla', 'price' => 25000]]));

        $preview = app(PriceImportPreview::class)->for($this->run_([['model' => 'Camry', 'price' => 33000], ['model' => 'Corolla', 'price' => 250000]]));

        $this->assertSame(2, $preview['price_up']);
        $this->assertCount(1, $preview['suspicious']);
        $this->assertSame(250000.0, $preview['suspicious'][0]['new']);
    }

    public function test_дубль_в_прайсе_считается_как_в_сборке_по_последней_строке(): void
    {
        $this->собрать($this->run_([['model' => 'Camry', 'price' => 30000]]));

        $preview = app(PriceImportPreview::class)->for($this->run_([['model' => 'Camry', 'price' => 99000], ['model' => 'Camry', 'price' => 30000]]));

        $this->assertSame(1, $preview['price_same']);
        $this->assertSame(0, $preview['price_up']);
    }

    public function test_строка_без_цены_снимает_предложение(): void
    {
        $this->собрать($this->run_([['model' => 'Camry', 'price' => 30000]]));

        $this->assertSame(1, app(PriceImportPreview::class)->for($this->run_([['model' => 'Camry', 'price' => null]]))['deactivated']);
    }

    public function test_администратор_видит_сводку_в_crm(): void
    {
        $run = $this->run_([['model' => 'Camry', 'price' => 30000]]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportPrices::class)
            ->call('preview', $run->id)
            ->assertSee('что изменится в каталоге')
            ->assertSee('Собрать каталог');
    }

    public function test_менеджеру_страница_импорта_закрыта(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(ImportPrices::canAccess());
    }

    public function test_выгрузка_не_превращает_текст_прайса_в_формулу(): void
    {
        $row = ReviewPriceList::textRow(['=HYPERLINK("http://example.test")', 12]);

        $this->assertInstanceOf(StringCell::class, $row->getCells()[0]);
        $this->assertNotInstanceOf(FormulaCell::class, $row->getCells()[0]);
    }
}
