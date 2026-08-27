<?php

namespace Tests\Feature;

use App\Catalog\DimensionsBackfill;
use App\Catalog\SupplierCountries;
use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Дозаполнение каталога тем, что уже есть в данных.
 *
 * Габариты разбор находил всегда, но при записи в стейджинг их не
 * перечислили — находка терялась между разбором и сборкой. Страну стекла
 * прайсы не несут вовсе, и её источник только один: сам поставщик.
 */
class CatalogEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function stagingRow(string $rawName, ?Product $product = null): PriceImportRow
    {
        $run = PriceImportRun::create(['filename' => 'test.xls', 'status' => 'parsed']);

        return PriceImportRow::create([
            'price_import_run_id' => $run->id,
            'row_no' => 1,
            'raw_name' => $rawName,
            'dialect' => 'kmk',
            'product_id' => $product?->id,
        ]);
    }

    // ------------------------------------------------------------------
    // габариты
    // ------------------------------------------------------------------

    /** Формат КМК: размеры в скобках через звёздочку. */
    public function test_габариты_в_скобках_достаются_из_названия(): void
    {
        $product = Product::factory()->create(['width_mm' => null, 'height_mm' => null]);
        $row = $this->stagingRow('BMW "5 F10" заднее ЭО ТЗ антенна (1315*813) КМК', $product);

        app(DimensionsBackfill::class)->run();

        $this->assertSame(1315, (int) $row->fresh()->width_mm);
        $this->assertSame(1315, (int) $product->fresh()->width_mm);
        $this->assertSame(813, (int) $product->fresh()->height_mm);
    }

    /** Формат AGC: без скобок и с толщиной третьим числом. */
    public function test_габариты_с_толщиной_достаются_из_названия(): void
    {
        $product = Product::factory()->create(['width_mm' => null]);
        $this->stagingRow('ВАЗ 2110 СТ ВЕТРОВОЕ ЗЛ OVERTINTED 1428x862x5,00 4503AGC', $product);

        app(DimensionsBackfill::class)->run();

        $this->assertSame(1428, (int) $product->fresh()->width_mm);
        $this->assertSame(862, (int) $product->fresh()->height_mm);
    }

    public function test_число_отверстий_тоже_достаётся(): void
    {
        $product = Product::factory()->create(['width_mm' => null, 'holes_count' => null]);
        $this->stagingRow('Лада боковое (1300*482) (4 отв.) КМК', $product);

        app(DimensionsBackfill::class)->run();

        $this->assertSame(4, (int) $product->fresh()->holes_count);
    }

    /**
     * У карточки бывает несколько строк от разных поставщиков, и размеры
     * у них расходятся на пару миллиметров. Показывать то одно, то другое
     * хуже, чем держаться первого найденного.
     */
    public function test_уже_заполненные_габариты_не_переписываются(): void
    {
        $product = Product::factory()->create(['width_mm' => 1400, 'height_mm' => 800]);
        $this->stagingRow('BMW заднее (1315*813) КМК', $product);

        app(DimensionsBackfill::class)->run();

        $this->assertSame(1400, (int) $product->fresh()->width_mm);
    }

    public function test_названия_без_размеров_пропускаются(): void
    {
        $product = Product::factory()->create(['width_mm' => null]);
        $this->stagingRow('Toyota Camry лобовое ЗП ДД', $product);

        $result = app(DimensionsBackfill::class)->run();

        $this->assertSame(0, $result['products']);
        $this->assertNull($product->fresh()->width_mm);
    }

    // ------------------------------------------------------------------
    // страна производства
    // ------------------------------------------------------------------

    public function test_бесспорные_заводы_заполняются(): void
    {
        $agc = Supplier::factory()->create(['name' => 'AGC', 'country' => null]);
        $xyg = Supplier::factory()->create(['name' => 'XYG', 'country' => null]);

        app(SupplierCountries::class)->fill();

        $this->assertSame('Япония', $agc->fresh()->country);
        $this->assertSame('Китай', $xyg->fresh()->country);
    }

    /**
     * Неверная страна — не косметика: человек доплатит за «Японию»,
     * которая окажется китайской. При сомнении оставляем пустым.
     */
    public function test_спорные_заводы_остаются_человеку(): void
    {
        $sl = Supplier::factory()->create(['name' => 'SL', 'country' => null]);

        $result = app(SupplierCountries::class)->fill();

        $this->assertNull($sl->fresh()->country);
        $this->assertContains('SL', $result['unknown']);
    }

    /** «Оригинал» и «Не указан» — не заводы, спрашивать про них нечего. */
    public function test_заглушки_поставщиков_в_отчёт_не_попадают(): void
    {
        Supplier::factory()->create(['name' => 'Не указан', 'country' => null]);
        Supplier::factory()->create(['name' => 'Оригинал', 'country' => null]);

        $this->assertSame([], app(SupplierCountries::class)->fill()['unknown']);
    }

    public function test_проставленную_руками_страну_не_трогаем(): void
    {
        $agc = Supplier::factory()->create(['name' => 'AGC', 'country' => 'Бельгия']);

        app(SupplierCountries::class)->fill();

        $this->assertSame('Бельгия', $agc->fresh()->country, 'менеджер знает про завод больше');
    }

    // ------------------------------------------------------------------
    // витрина
    // ------------------------------------------------------------------

    public function test_команда_отчитывается_о_спорных(): void
    {
        Supplier::factory()->create(['name' => 'SL', 'country' => null]);

        $this->artisan('catalog:enrich')
            ->expectsOutputToContain('SL')
            ->assertSuccessful();
    }
}
