<?php

namespace Tests\Feature;

use App\Catalog\ModelMerger;
use App\Catalog\ModelNormalizer;
use App\Models\CarBrand;
use App\Models\CarGeneration;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Сведение разъехавшегося справочника моделей.
 *
 * Прайсы называют одну машину по-разному, и каждое написание заводило
 * отдельную карточку: «5», «5 SER», «5 кругл» — это всё BMW пятой серии.
 * Здесь проверяется и то, что дубли сводятся, и — важнее — что разные
 * машины не склеиваются: лишняя карточка в меню это некрасиво,
 * а склеенные Prius и Prius C это отгруженное не то стекло.
 */
class ModelMergerTest extends TestCase
{
    use RefreshDatabase;

    private CarBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = CarBrand::factory()->create(['name' => 'BMW', 'slug' => 'bmw']);
    }

    private function model(string $name, int $products = 1): CarModel
    {
        return CarModel::factory()->create([
            'car_brand_id' => $this->brand->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'products_count' => $products,
        ]);
    }

    private function fitmentFor(CarModel $model, ?int $from = null): Fitment
    {
        return Fitment::factory()->create([
            'product_id' => Product::factory(),
            'car_model_id' => $model->id,
            'year_from' => $from,
            'year_to' => null,
        ]);
    }

    private function merger(): ModelMerger
    {
        return new ModelMerger(new ModelNormalizer);
    }

    // ------------------------------------------------------------------
    // план
    // ------------------------------------------------------------------

    public function test_план_ничего_не_меняет(): void
    {
        $this->model('5');
        $this->model('5 кругл');

        $this->merger()->plan();

        $this->assertSame(2, CarModel::count());
    }

    public function test_дубли_одной_модели_сводятся(): void
    {
        $this->model('5', products: 10);
        $this->model('5 кругл');
        $this->model('5 SER');

        $removed = $this->merger()->apply();

        $this->assertSame(2, $removed);
        $this->assertSame(['5'], CarModel::pluck('name')->all());
    }

    public function test_разные_машины_остаются_врозь(): void
    {
        $this->model('X5');
        $this->model('X6');

        $this->assertSame(0, $this->merger()->apply());
        $this->assertSame(2, CarModel::count());
    }

    // ------------------------------------------------------------------
    // кто остаётся
    // ------------------------------------------------------------------

    /** Правильно названная карточка остаётся — её адрес уже в поисковой выдаче. */
    public function test_победителем_становится_правильно_названная(): void
    {
        $right = $this->model('5', products: 1);
        $this->model('5 кругл', products: 99);

        $this->merger()->apply();

        $this->assertSame($right->id, CarModel::sole()->id);
    }

    /** Если правильной нет, побеждает та, у которой больше товаров. */
    public function test_без_канонической_побеждает_крупная(): void
    {
        $small = $this->model('5 кругл', products: 2);
        $big = $this->model('5 квадр', products: 40);

        $this->merger()->apply();

        $survivor = CarModel::sole();

        $this->assertSame($big->id, $survivor->id);
        $this->assertSame('5', $survivor->name, 'имя приводится к каноническому');
        $this->assertNotSame($small->id, $survivor->id);
    }

    // ------------------------------------------------------------------
    // что переезжает
    // ------------------------------------------------------------------

    public function test_применимости_переезжают_к_победителю(): void
    {
        $winner = $this->model('5', products: 10);
        $loser = $this->model('5 кругл');

        $moved = $this->fitmentFor($loser, 2003);

        $this->merger()->apply();

        $this->assertSame($winner->id, $moved->fresh()->car_model_id);
    }

    /**
     * У победителя может быть точно такая же применимость. Переносить нечего:
     * уникальный ключ не пустит, а дубль в каталоге не нужен.
     */
    public function test_одинаковая_применимость_не_дублируется(): void
    {
        $winner = $this->model('5', products: 10);
        $loser = $this->model('5 кругл');

        $product = Product::factory()->create();

        foreach ([$winner, $loser] as $model) {
            Fitment::factory()->create([
                'product_id' => $product->id,
                'car_model_id' => $model->id,
                'year_from' => 2003,
                'year_to' => null,
            ]);
        }

        $this->merger()->apply();

        $this->assertSame(1, Fitment::where('product_id', $product->id)->count());
    }

    public function test_поколения_переезжают(): void
    {
        $winner = $this->model('5', products: 10);
        $loser = $this->model('5 кругл');

        $generation = CarGeneration::create([
            'car_model_id' => $loser->id,
            'name' => 'E39',
            'year_from' => 1995,
        ]);

        $this->merger()->apply();

        $this->assertSame($winner->id, $generation->fresh()->car_model_id);
    }

    /** Машина клиента в его карточке не должна осиротеть. */
    public function test_машина_клиента_переезжает(): void
    {
        $winner = $this->model('5', products: 10);
        $loser = $this->model('5 кругл');

        $customer = Customer::factory()->create(['car_model_id' => $loser->id]);

        $this->merger()->apply();

        $this->assertSame($winner->id, $customer->fresh()->car_model_id);
    }

    // ------------------------------------------------------------------
    // граница автоматики
    // ------------------------------------------------------------------

    /**
     * «Model» и «Model S» — не одна машина, а Tesla Model S. Одинокая
     * заглавная буква в хвосте подозрительна, и трогать её нельзя.
     */
    public function test_одинокая_заглавная_буква_не_сводится(): void
    {
        $tesla = CarBrand::factory()->create(['name' => 'Tesla', 'slug' => 'tesla']);

        foreach (['Model', 'Model S'] as $name) {
            CarModel::factory()->create([
                'car_brand_id' => $tesla->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'products_count' => 1,
            ]);
        }

        $this->assertSame(0, $this->merger()->apply());
        $this->assertSame(2, CarModel::where('car_brand_id', $tesla->id)->count());
    }

    /**
     * Зато выводок букв на одной основе — это индексы поколений:
     * Astra F, G, H, J.
     */
    public function test_выводок_букв_сводится(): void
    {
        $opel = CarBrand::factory()->create(['name' => 'Opel', 'slug' => 'opel']);

        foreach (['Astra', 'Astra F', 'Astra G', 'Astra H'] as $name) {
            CarModel::factory()->create([
                'car_brand_id' => $opel->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'products_count' => 1,
            ]);
        }

        $this->assertSame(3, $this->merger()->apply());
        $this->assertSame(['Astra'], CarModel::where('car_brand_id', $opel->id)->pluck('name')->all());
    }

    /** То, что решено не трогать, показывается человеку — иначе о нём забудут. */
    public function test_пропущенное_попадает_в_отчёт(): void
    {
        $tesla = CarBrand::factory()->create(['name' => 'Tesla', 'slug' => 'tesla']);

        foreach (['Model', 'Model S'] as $name) {
            CarModel::factory()->create([
                'car_brand_id' => $tesla->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'products_count' => 1,
            ]);
        }

        $skipped = $this->merger()->skipped();

        $this->assertCount(1, $skipped);
        $this->assertSame('Model S', $skipped->first()['skipped']->first()->name);
    }

    // ------------------------------------------------------------------
    // команда
    // ------------------------------------------------------------------

    public function test_команда_без_ключа_только_показывает(): void
    {
        $this->model('5', products: 10);
        $this->model('5 кругл');

        $this->artisan('catalog:merge-models')
            ->expectsOutputToContain('Это только план')
            ->assertSuccessful();

        $this->assertSame(2, CarModel::count());
    }

    public function test_команда_с_ключом_применяет(): void
    {
        $this->model('5', products: 10);
        $this->model('5 кругл');

        $this->artisan('catalog:merge-models --apply')->assertSuccessful();

        $this->assertSame(1, CarModel::count());
    }
}
