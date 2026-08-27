<?php

namespace Tests\Feature;

use App\Enums\GlassType;
use App\Models\Product;
use App\Services\Scraper\AutoGlassScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScraperTest extends TestCase
{
    use RefreshDatabase;

    private function camry(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'Toyota Camry 70 лобовое стекло',
            'eurocode' => '8414AGNGNVW',
            'glass_type' => GlassType::Windshield,
            'camera' => true,
            'rain_sensor' => true,
            'heated_wipers' => true,
            'vin_window' => true,
            'width_mm' => null,
            'height_mm' => null,
            'image_path' => null,
        ], $overrides));
    }

    public function test_scraper_generates_image_for_product(): void
    {
        Storage::fake('public');

        $product = $this->camry();
        $scraper = new AutoGlassScraper;

        $data = $scraper->fetchDataForProduct($product);

        $this->assertNotNull($data);

        $updated = $scraper->applyDataToProduct($product, $data);

        $this->assertTrue($updated['image']);

        $product->refresh();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    /**
     * Габариты скрапер не придумывает.
     *
     * Тест раньше требовал обратного — assertGreaterThan(0, width_mm), — и
     * поведение ему соответствовало: размеры добирались через rand() по типу
     * стекла. На витрине они выглядели как характеристика, хотя к конкретному
     * стеклу отношения не имели: два одинаковых лобовых Camry получали
     * 1486×862 и 1428×858.
     *
     * Покупатель меряет снятое стекло и сверяет с числом на странице — для
     * него это последняя проверка перед заказом. Выдуманное число здесь хуже
     * пустоты, потому что выглядит достоверно.
     */
    public function test_scraper_does_not_invent_dimensions(): void
    {
        Storage::fake('public');

        $product = $this->camry();
        $scraper = new AutoGlassScraper;

        $updated = $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product));

        $this->assertFalse($updated['dimensions']);

        $product->refresh();

        $this->assertNull($product->width_mm);
        $this->assertNull($product->height_mm);
    }

    /** Известные размеры остаются нетронутыми. */
    public function test_scraper_keeps_known_dimensions(): void
    {
        Storage::fake('public');

        $product = $this->camry(['width_mm' => 1428, 'height_mm' => 862]);
        $scraper = new AutoGlassScraper;

        $scraper->applyDataToProduct($product, $scraper->fetchDataForProduct($product));

        $product->refresh();

        $this->assertSame(1428, (int) $product->width_mm);
        $this->assertSame(862, (int) $product->height_mm);
    }
}
