<?php

namespace Tests\Feature;

use App\Enums\GlassType;
use App\Models\PriceImportRow;
use App\Models\PriceImportRun;
use App\Models\Product;
use App\Services\Scraper\AutoGlassScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Габариты не выдумываются.
 *
 * Для покупателя размеры — последняя проверка перед заказом: он меряет
 * снятое стекло и сверяет с числом на странице. Какое-то время генератор
 * изображений дописывал сюда rand() по типу стекла, и два одинаковых
 * лобовых Camry получали 1486×862 и 1428×858. Такое число выглядит как факт
 * и приводит к заказу не того стекла — то есть хуже пустоты.
 */
class DimensionHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function glass(GlassType $type = GlassType::Windshield): Product
    {
        return Product::factory()->create([
            'glass_type' => $type->value,
            'width_mm' => null,
            'height_mm' => null,
            'image_path' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // генератор
    // ------------------------------------------------------------------

    /** Ради этого теста всё и написано: пусто должно оставаться пустым. */
    public function test_генератор_не_придумывает_габариты(): void
    {
        $product = $this->glass();

        $scraper = app(AutoGlassScraper::class);
        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product) ?? []);

        $product->refresh();

        $this->assertNull($product->width_mm, 'габарит взялся из ниоткуда');
        $this->assertNull($product->height_mm);
    }

    /**
     * Два одинаковых стекла обязаны остаться одинаковыми. Именно это
     * и ломалось: случайные числа расходились на десятки миллиметров.
     */
    public function test_одинаковые_стёкла_получают_одинаковый_результат(): void
    {
        $first = $this->glass();
        $second = $this->glass();

        $scraper = app(AutoGlassScraper::class);

        foreach ([$first, $second] as $product) {
            $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product) ?? []);
        }

        $this->assertSame($first->fresh()->width_mm, $second->fresh()->width_mm);
    }

    /** Настоящие габариты генератор не трогает. */
    public function test_известные_габариты_сохраняются(): void
    {
        $product = Product::factory()->create([
            'glass_type' => GlassType::Windshield->value,
            'width_mm' => 1428,
            'height_mm' => 862,
        ]);

        $scraper = app(AutoGlassScraper::class);
        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product) ?? []);

        $this->assertSame(1428, (int) $product->fresh()->width_mm);
        $this->assertSame(862, (int) $product->fresh()->height_mm);
    }

    // ------------------------------------------------------------------
    // уборка того, что уже успели напридумывать
    // ------------------------------------------------------------------

    private function stagingRow(Product $product, ?int $width): void
    {
        $run = PriceImportRun::create(['filename' => 'test.xls', 'status' => 'applied']);

        PriceImportRow::create([
            'price_import_run_id' => $run->id,
            'row_no' => 1,
            'raw_name' => 'тест',
            'product_id' => $product->id,
            'width_mm' => $width,
        ]);
    }

    public function test_неподтверждённые_габариты_стираются(): void
    {
        $invented = Product::factory()->create(['width_mm' => 1450, 'height_mm' => 870]);
        $this->stagingRow($invented, null);

        $this->artisan('catalog:drop-invented --apply')->assertSuccessful();

        $invented->refresh();

        $this->assertNull($invented->width_mm);
        $this->assertNull($invented->height_mm);
    }

    /** Настоящее — то, что разбор нашёл в самой строке прайса. */
    public function test_подтверждённые_прайсом_остаются(): void
    {
        $real = Product::factory()->create(['width_mm' => 1428, 'height_mm' => 862]);
        $this->stagingRow($real, 1428);

        $this->artisan('catalog:drop-invented --apply')->assertSuccessful();

        $this->assertSame(1428, (int) $real->fresh()->width_mm);
    }

    public function test_без_ключа_ничего_не_стирается(): void
    {
        $product = Product::factory()->create(['width_mm' => 1450]);
        $this->stagingRow($product, null);

        $this->artisan('catalog:drop-invented')
            ->expectsOutputToContain('только подсчёт')
            ->assertSuccessful();

        $this->assertSame(1450, (int) $product->fresh()->width_mm);
    }

    // ------------------------------------------------------------------
    // тонировка и описание
    // ------------------------------------------------------------------

    /**
     * Цвет по умолчанию «зелёный» получили 5941 карточка из 5957. Для лобового
     * это чаще всего правда, но покупатель выбирает между «зелёное» и «без
     * тонировки» осознанно — утверждать цвет там, где мы его не знаем, нельзя.
     */
    public function test_тонировка_не_ставится_по_умолчанию(): void
    {
        $product = Product::factory()->create(['eurocode' => null, 'tint' => null]);

        $scraper = app(AutoGlassScraper::class);
        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product));

        $this->assertNull($product->fresh()->tint);
    }

    /** Буквы еврокода цвет действительно кодируют — это законное основание. */
    public function test_тонировка_из_еврокода_проставляется(): void
    {
        $product = Product::factory()->create(['eurocode' => '4185AGSV', 'tint' => null]);

        $scraper = app(AutoGlassScraper::class);
        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product));

        $this->assertSame('green', $product->fresh()->tint);
    }

    public function test_тонировка_без_основания_стирается(): void
    {
        $guessed = Product::factory()->create(['eurocode' => null, 'tint' => 'green']);
        $known = Product::factory()->create(['eurocode' => '4185AGSV', 'tint' => 'green']);

        $this->artisan('catalog:drop-invented --apply')->assertSuccessful();

        $this->assertNull($guessed->fresh()->tint);
        $this->assertSame('green', $known->fresh()->tint, 'у этого цвет в еврокоде');
    }

    /**
     * Шаблонная фраза повторяла заголовок и утверждала то, чего мы не знаем.
     * Заодно пять тысяч почти одинаковых страниц — для поиска пустой текст.
     */
    public function test_описание_не_сочиняется(): void
    {
        $product = Product::factory()->create(['description' => null]);

        $scraper = app(AutoGlassScraper::class);
        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product));

        $this->assertNull($product->fresh()->description);
    }

    /** Написанное человеком описание уборка не трогает. */
    public function test_настоящее_описание_сохраняется(): void
    {
        $product = Product::factory()->create([
            'description' => 'Стекло с подогревом зоны щёток, проверено на стенде.',
        ]);

        $this->artisan('catalog:drop-invented --apply')->assertSuccessful();

        $this->assertNotNull($product->fresh()->description);
    }

    // ------------------------------------------------------------------
    // витрина
    // ------------------------------------------------------------------

    /** Без габаритов чертёж просто не подписывает стороны, а не пишет «0 мм». */
    public function test_карточка_без_габаритов_не_показывает_нулей(): void
    {
        $product = $this->glass();

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertDontSee('0 × 0 мм')
            ->assertDontSee('Ширина: 0');
    }
}
