<?php

namespace Tests\Feature;

use App\Catalog\ModelYears;
use App\Catalog\ProductFilter;
use App\Catalog\ProductQuery;
use App\Models\CarBrand;
use App\Models\CarGeneration;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Подбор по году выпуска.
 *
 * Camry 2006 и Camry 2018 — разные стёкла, и покупатель их не различает.
 * Год — единственное, что он про свою машину знает точно: поколение он
 * не назовёт, а VIN пойдёт искать не всякий.
 */
class YearFilterTest extends TestCase
{
    use RefreshDatabase;

    private function camry(): CarModel
    {
        $brand = CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota']);

        return CarModel::factory()->visible()->create([
            'car_brand_id' => $brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
        ]);
    }

    private function glass(CarModel $model, ?int $from, ?int $to, string $name): Product
    {
        $product = Product::factory()->create(['name' => $name]);

        Fitment::factory()->create([
            'product_id' => $product->id,
            'car_model_id' => $model->id,
            'year_from' => $from,
            'year_to' => $to,
        ]);

        return $product;
    }

    private function found(CarModel $model, ?int $year): array
    {
        return app(ProductQuery::class)
            ->build(new ProductFilter(carModelId: $model->id, year: $year))
            ->pluck('name')
            ->all();
    }

    // ------------------------------------------------------------------
    // отбор
    // ------------------------------------------------------------------

    public function test_год_отсекает_стекло_от_другого_поколения(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');
        $this->glass($camry, 2018, null, 'Лобовое Camry 70');

        $this->assertSame(['Лобовое Camry 40'], $this->found($camry, 2008));
        $this->assertSame(['Лобовое Camry 70'], $this->found($camry, 2020));
    }

    public function test_без_года_видно_всё(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');
        $this->glass($camry, 2018, null, 'Лобовое Camry 70');

        $this->assertCount(2, $this->found($camry, null));
    }

    /** Границы диапазона включаются: 2011 год — это ещё сороковая Camry. */
    public function test_границы_диапазона_входят_в_подбор(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');

        $this->assertCount(1, $this->found($camry, 2006));
        $this->assertCount(1, $this->found($camry, 2011));
        $this->assertCount(0, $this->found($camry, 2005));
        $this->assertCount(0, $this->found($camry, 2012));
    }

    /** Открытый конец — «по настоящее время», а не «до сих пор не выпускается». */
    public function test_открытый_конец_диапазона_подходит_свежим_годам(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2018, null, 'Лобовое Camry 70');

        $this->assertCount(1, $this->found($camry, (int) date('Y')));
    }

    /**
     * Год в прайсах стоит не всегда. Спрятать из-за этого стекло, которое
     * может подойти, дороже, чем показать лишнее: во втором случае менеджер
     * уточнит по VIN, в первом клиент просто уйдёт.
     */
    public function test_применимость_без_годов_проходит_любой_год(): void
    {
        $camry = $this->camry();
        $this->glass($camry, null, null, 'Лобовое без годов');

        $this->assertCount(1, $this->found($camry, 1999));
        $this->assertCount(1, $this->found($camry, 2024));
    }

    // ------------------------------------------------------------------
    // разбор параметра
    // ------------------------------------------------------------------

    public function test_мусор_в_адресе_годом_не_считается(): void
    {
        foreach (['абв', '0', '1800', '2200', ''] as $garbage) {
            $filter = ProductFilter::fromRequest(
                Request::create('/', 'GET', ['year' => $garbage])
            );

            $this->assertNull($filter->year, "«{$garbage}» не должен стать годом");
        }
    }

    /** Осенью новые машины уже продаются следующим годом. */
    public function test_следующий_год_принимается(): void
    {
        $next = (int) date('Y') + 1;

        $filter = ProductFilter::fromRequest(
            Request::create('/', 'GET', ['year' => $next])
        );

        $this->assertSame($next, $filter->year);
    }

    // ------------------------------------------------------------------
    // сохранение фильтров в ссылках
    // ------------------------------------------------------------------

    /** Выбрал год, нажал «лобовое» — год не должен потеряться. */
    public function test_ссылка_на_тип_стекла_сохраняет_год(): void
    {
        $filter = new ProductFilter(year: 2015, inStockOnly: true);

        $query = $filter->toQuery(['type' => 'windshield']);

        $this->assertSame(2015, $query['year']);
        $this->assertSame('windshield', $query['type']);
        $this->assertSame(1, $query['in_stock']);
    }

    public function test_сброс_года_убирает_его_из_ссылки(): void
    {
        $query = (new ProductFilter(year: 2015))->toQuery(['year' => null]);

        $this->assertArrayNotHasKey('year', $query);
    }

    // ------------------------------------------------------------------
    // список годов
    // ------------------------------------------------------------------

    public function test_годы_берутся_из_применимостей(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');

        $years = app(ModelYears::class)->for($camry);

        $this->assertSame(2011, $years[0], 'свежие годы сверху');
        $this->assertSame(2006, end($years));
    }

    /** Неопубликованное не должно приносить в подбор свои годы. */
    public function test_скрытые_карточки_годов_не_добавляют(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Видимое');

        $hidden = Product::factory()->create(['name' => 'Скрытое', 'is_published' => false]);
        Fitment::factory()->create([
            'product_id' => $hidden->id,
            'car_model_id' => $camry->id,
            'year_from' => 1990,
            'year_to' => 1995,
        ]);

        $years = app(ModelYears::class)->for($camry);

        $this->assertSame(2006, end($years));
    }

    public function test_без_годов_список_пустой(): void
    {
        $camry = $this->camry();
        $this->glass($camry, null, null, 'Лобовое без годов');

        $this->assertSame([], app(ModelYears::class)->for($camry));
    }

    // ------------------------------------------------------------------
    // поколение как ответ
    // ------------------------------------------------------------------

    public function test_поколение_определяется_по_году(): void
    {
        $camry = $this->camry();

        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '40', 'year_from' => 2006, 'year_to' => 2011]);
        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '70', 'year_from' => 2018]);

        $this->assertSame('40', app(ModelYears::class)->generationFor($camry, 2008)?->title());
        $this->assertSame('70', app(ModelYears::class)->generationFor($camry, 2020)?->title());
    }

    /** Справочник заполнен не везде — придумывать за него нельзя. */
    public function test_без_справочника_поколение_не_показывается(): void
    {
        $camry = $this->camry();

        $this->assertNull(app(ModelYears::class)->generationFor($camry, 2008));
        $this->assertNull(app(ModelYears::class)->generationFor($camry, null));
    }

    /**
     * Про поколение без года начала нельзя сказать, что оно покрывает 2006-й:
     * мы не знаем, когда оно началось. Такое в ответ не показываем — и оно
     * не должно перебивать настоящее, у которого годы есть.
     */
    public function test_поколение_без_годов_не_перебивает_настоящее(): void
    {
        $camry = $this->camry();

        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '18']);
        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '40', 'year_from' => 2006, 'year_to' => 2011]);

        $this->assertSame('40', app(ModelYears::class)->generationFor($camry, 2008)?->title());
    }

    public function test_поколение_только_без_годов_не_показывается(): void
    {
        $camry = $this->camry();

        CarGeneration::create(['car_model_id' => $camry->id, 'name' => 'XV70']);

        $this->assertNull(app(ModelYears::class)->generationFor($camry, 2008));
    }

    /**
     * Конец выпуска в прайсах не пишут почти никогда. Без поправки витрина
     * скажет «2011 — н.в.» рядом с поколением, которое идёт с 2018-го.
     */
    public function test_конец_поколения_достраивается_по_следующему(): void
    {
        $camry = $this->camry();

        $fifty = CarGeneration::create(['car_model_id' => $camry->id, 'name' => '50', 'year_from' => 2011]);
        CarGeneration::create(['car_model_id' => $camry->id, 'name' => '70', 'year_from' => 2018]);

        $this->assertSame(2017, $fifty->effectiveYearTo());
        $this->assertSame('2011 — 2017', $fifty->yearsLabel());
    }

    /** Рестайлинг идёт внутри поколения и его не заканчивает. */
    public function test_рестайлинг_поколение_не_обрывает(): void
    {
        $camry = $this->camry();

        $seventy = CarGeneration::create(['car_model_id' => $camry->id, 'name' => '70', 'year_from' => 2018]);
        CarGeneration::create([
            'car_model_id' => $camry->id,
            'name' => '70 рестайлинг',
            'year_from' => 2021,
            'is_restyling' => true,
        ]);

        $this->assertNull($seventy->effectiveYearTo());
    }

    // ------------------------------------------------------------------
    // страница
    // ------------------------------------------------------------------

    public function test_на_странице_модели_есть_выбор_года(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');

        $this->get('/catalog/toyota/camry')
            ->assertOk()
            ->assertSee('Год выпуска')
            ->assertSee('2006');
    }

    public function test_выбранный_год_отсекает_карточки_на_странице(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');
        $this->glass($camry, 2018, null, 'Лобовое Camry 70');

        $this->get('/catalog/toyota/camry?year=2008')
            ->assertOk()
            ->assertSee('Лобовое Camry 40')
            ->assertDontSee('Лобовое Camry 70');
    }

    /** Год отсекает сильнее остальных фильтров — о нём и надо сказать. */
    public function test_пустая_выдача_подсказывает_снять_год(): void
    {
        $camry = $this->camry();
        $this->glass($camry, 2006, 2011, 'Лобовое Camry 40');

        $this->get('/catalog/toyota/camry?year=2020')
            ->assertOk()
            ->assertSee('дело в годе выпуска');
    }
}
