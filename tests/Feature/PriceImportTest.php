<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportPrices;
use App\Models\PriceImportRun;
use App\Models\User;
use App\Services\PriceList\PriceListImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class PriceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_xlsx_попадает_в_стейджинг(): void
    {
        $path = $this->xlsx([
            ['Наименование', 'Ед.изм', 'Картинка', 'Оптовая цена', 'Валюта', 'Артикул'],
            ['Стекло ветровое Renault Megane II 2002-07 з/п ТЗ 7260AGNGYV SL', 'шт', '', '18500', 'KZT', '7260'],
        ]);

        $run = app(PriceListImporter::class)->import($path, 'тест');

        $row = $run->rows()->sole();

        $this->assertSame('parsed', $run->status);
        $this->assertSame(1, $run->rows_total);
        $this->assertSame('Renault', $row->brand_name);
        $this->assertSame('windshield', $row->glass_type);
        $this->assertSame('7260AGNGYV', $row->eurocode);
        $this->assertEquals(18500, (float) $row->price);
    }

    public function test_команда_импортирует_тот_же_файл(): void
    {
        $path = $this->xlsx([
            ['Наименование', 'Ед.изм', 'Картинка', 'Оптовая цена', 'Валюта', 'Артикул'],
            ['Стекло ветровое Renault Megane II 2002-07 з/п ТЗ 7260AGNGYV SL', 'шт', '', '18500', 'KZT', '7260'],
        ]);

        $this->artisan('price:import', ['file' => $path, '--source' => 'artisan'])
            ->assertSuccessful();

        $this->assertSame(1, PriceImportRun::count());
        $this->assertSame('artisan', PriceImportRun::sole()->source);
    }

    public function test_админ_открывает_импорт_в_crm(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(ImportPrices::getUrl())
            ->assertOk()
            ->assertSee('Импорт прайса');
    }

    public function test_менеджер_импорт_не_откроет(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(ImportPrices::getUrl())
            ->assertForbidden();
    }

    public function test_с_экрана_админ_разбирает_прайс(): void
    {
        $path = $this->xlsx([
            ['Наименование', 'Ед.изм', 'Картинка', 'Оптовая цена', 'Валюта', 'Артикул'],
            ['Стекло ветровое Renault Megane II 2002-07 з/п ТЗ 7260AGNGYV SL', 'шт', '', '18500', 'KZT', '7260'],
        ]);

        $relative = 'prices/uploads/kmk.xlsx';
        \Illuminate\Support\Facades\Storage::disk('local')->put($relative, file_get_contents($path));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ImportPrices::class)
            ->set('data.file', [$relative])
            ->set('data.source', 'CRM тест')
            ->call('import');

        $run = PriceImportRun::sole();
        $this->assertSame('parsed', $run->status);
        $this->assertSame('CRM тест', $run->source);
        $this->assertSame(1, $run->rows_total);
    }

    /** @param  list<list<string>>  $rows */
    private function xlsx(array $rows): string
    {
        $path = sys_get_temp_dir().'/price-'.uniqid('', true).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }
}
