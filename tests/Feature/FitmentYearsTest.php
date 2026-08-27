<?php

namespace Tests\Feature;

use App\Catalog\FitmentYears;
use App\Models\CarBrand;
use App\Models\CarGeneration;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Достройка годов применимости по поколению.
 *
 * В прайсах годы пишут по-разному: где-то диапазоном («Camry 2006–2011»),
 * где-то одним поколением («Camry 70»). Во втором случае разбор находит
 * поколение, но годы в применимости остаются пустыми — а пустые годы
 * проходят любой фильтр, и Camry 70 попадает в выдачу за 2008-й.
 */
class FitmentYearsTest extends TestCase
{
    use RefreshDatabase;

    private function camry(): CarModel
    {
        $brand = CarBrand::factory()->create(['name' => 'Toyota', 'slug' => 'toyota']);

        return CarModel::factory()->create([
            'car_brand_id' => $brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
        ]);
    }

    private function fitment(CarModel $model, ?CarGeneration $generation, ?int $from, ?int $to): Fitment
    {
        return Fitment::factory()->create([
            'product_id' => Product::factory(),
            'car_model_id' => $model->id,
            'car_generation_id' => $generation?->id,
            'year_from' => $from,
            'year_to' => $to,
        ]);
    }

    public function test_годы_переносятся_с_поколения(): void
    {
        $camry = $this->camry();
        $seventy = CarGeneration::create([
            'car_model_id' => $camry->id,
            'name' => '70',
            'year_from' => 2018,
            'year_to' => 2024,
        ]);

        $fitment = $this->fitment($camry, $seventy, null, null);

        $this->assertSame(1, app(FitmentYears::class)->backfill());

        $fitment->refresh();

        $this->assertSame(2018, (int) $fitment->year_from);
        $this->assertSame(2024, (int) $fitment->year_to);
    }

    /**
     * Конец у поколения тоже почти никогда не проставлен — берём достроенный
     * по следующему поколению, иначе Camry 50 останется «по настоящее время».
     */
    public function test_конец_достраивается_по_следующему_поколению(): void
    {
        $camry = $this->camry();
        $fifty = CarGeneration::create(['car_model_id' => $camry->id, 'name' => '50', 'year_from' => 2011]);
        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '70', 'year_from' => 2018]);

        $fitment = $this->fitment($camry, $fifty, null, null);

        app(FitmentYears::class)->backfill();

        $this->assertSame(2017, (int) $fitment->refresh()->year_to);
    }

    /**
     * Свои годы точнее: одно стекло внутри поколения может идти не весь
     * его срок — дорестайлинг, смена поставщика ветрового стекла.
     */
    public function test_проставленные_годы_не_переписываются(): void
    {
        $camry = $this->camry();
        $seventy = CarGeneration::create([
            'car_model_id' => $camry->id,
            'name' => '70',
            'year_from' => 2018,
            'year_to' => 2024,
        ]);

        $fitment = $this->fitment($camry, $seventy, 2020, 2022);

        $this->assertSame(0, app(FitmentYears::class)->backfill());

        $fitment->refresh();

        $this->assertSame(2020, (int) $fitment->year_from);
        $this->assertSame(2022, (int) $fitment->year_to);
    }

    public function test_без_поколения_ничего_не_меняется(): void
    {
        $fitment = $this->fitment($this->camry(), null, null, null);

        $this->assertSame(0, app(FitmentYears::class)->backfill());
        $this->assertNull($fitment->refresh()->year_from);
    }

    /** Поколение без годов переносить нечего — придумывать нельзя. */
    public function test_поколение_без_годов_пропускается(): void
    {
        $camry = $this->camry();
        $unknown = CarGeneration::create(['car_model_id' => $camry->id, 'name' => 'XV70']);

        $fitment = $this->fitment($camry, $unknown, null, null);

        $this->assertSame(0, app(FitmentYears::class)->backfill());
        $this->assertNull($fitment->refresh()->year_from);
    }

    /** Повторный запуск ничего не портит: команду гоняют после каждой сборки. */
    public function test_повторный_запуск_безопасен(): void
    {
        $camry = $this->camry();
        $seventy = CarGeneration::create([
            'car_model_id' => $camry->id,
            'name' => '70',
            'year_from' => 2018,
            'year_to' => 2024,
        ]);

        $this->fitment($camry, $seventy, null, null);

        $this->artisan('catalog:fill-years')->assertSuccessful();
        $this->artisan('catalog:fill-years')->assertSuccessful();

        $this->assertSame(1, Fitment::whereNotNull('year_from')->count());
    }
}
