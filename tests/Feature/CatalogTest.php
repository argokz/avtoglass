<?php

namespace Tests\Feature;

use App\Enums\GlassType;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    /** Собирает марку, модель и стекло, которое к ней подходит. */
    private function glassFor(CarModel $model, array $state = []): Product
    {
        $product = Product::factory()->create($state);

        Fitment::factory()->create([
            'product_id' => $product->id,
            'car_model_id' => $model->id,
        ]);

        // На витрине не бывает товара без предложения из прайса, и поиск
        // ищет именно по предложениям — без него товар не найдётся вовсе.
        Offer::factory()->create(['product_id' => $product->id]);

        return $product;
    }

    private function camry(): CarModel
    {
        $brand = CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota']);

        return CarModel::factory()->visible()->create([
            'car_brand_id' => $brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
        ]);
    }

    public function test_главная_открывается(): void
    {
        CarBrand::factory()->visible()->create(['name' => 'Toyota']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Toyota')
            ->assertSee('Стекло на какую машину?')
            ->assertSee('Войти')
            ->assertSee(route('account.login'), false)
            ->assertSee('images/logo.png', false);
    }

    public function test_главная_живёт_на_повторном_запросе_через_database_кэш(): void
    {
        config(['cache.default' => 'database']);

        CarBrand::factory()->visible()->create(['name' => 'Toyota']);

        $this->get('/')->assertOk()->assertSee('Toyota');
        $this->get('/')->assertOk()->assertSee('Toyota');
        $this->get(route('catalog.brands'))->assertOk()->assertSee('Toyota');
    }

    /**
     * Цены на работы стоят на главной, а не прячутся за «уточняйте
     * по телефону»: это первый вопрос покупателя, и человек, получивший
     * ответ до звонка, звонит уже готовым покупать.
     */
    public function test_главная_показывает_цены_на_работы(): void
    {
        Service::factory()->create([
            'name' => 'Установка лобового стекла',
            'price' => 15000,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Работы и цены')
            ->assertSee('Установка лобового стекла')
            ->assertSee('15 000 ₸');
    }

    /** Выключенная работа на витрину не попадает. */
    public function test_выключенная_работа_на_главной_не_показывается(): void
    {
        Service::factory()->create([
            'name' => 'Снятие тонировки',
            'is_active' => false,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Снятие тонировки');
    }

    /** Счётчики на главной склоняются: «1 модель», а не «1 моделей». */
    public function test_счётчики_на_главной_склоняются(): void
    {
        $brand = CarBrand::factory()->visible()->create(['models_count' => 1]);
        CarModel::factory()->for($brand, 'brand')->create(['products_count' => 1]);

        $this->get('/')
            ->assertOk()
            ->assertSee('1 модель')
            ->assertDontSee('1 моделей');
    }

    public function test_главная_не_обещает_наличие_пока_тумблер_выключен(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('в наличии и под заказ')
            ->assertSee('Наличие и срок подтверждает менеджер');
    }

    public function test_тип_стекла_с_главной_ведёт_в_каталог(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('type=windshield', false)
            ->assertSee('Лобовые');
    }

    public function test_каталог_протаскивает_тип_стекла_до_модели(): void
    {
        $model = $this->camry();

        $this->get(route('catalog.brands', ['type' => 'windshield']))
            ->assertOk()
            ->assertSee('лобовое стекло');

        $this->get(route('catalog.models', ['brand' => $model->brand, 'type' => 'windshield']))
            ->assertOk()
            ->assertSee('type=windshield', false);
    }

    public function test_страница_модели_показывает_подходящее_стекло(): void
    {
        $model = $this->camry();
        $product = $this->glassFor($model, ['name' => 'Лобовое стекло Camry']);

        $this->get('/catalog/toyota/camry')
            ->assertOk()
            ->assertSee('Лобовое стекло Camry');

        $this->assertSame(1, Product::count(), 'товар должен быть один');
        $this->assertTrue($product->is_published);
    }

    /**
     * Ключевое правило витрины: неопубликованное не показываем.
     * Через него в каталог протекли бы неразобранные строки прайса.
     */
    public function test_неопубликованные_карточки_скрыты_везде(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Секретное стекло', 'is_published' => false]);

        $this->get('/catalog/toyota/camry')->assertOk()->assertDontSee('Секретное стекло');
        $this->get('/search?q=Секретное')->assertOk()->assertDontSee('Секретное стекло');
    }

    public function test_неопубликованная_карточка_отдаёт_404(): void
    {
        $product = Product::factory()->unpublished()->create();

        $this->get("/tovar/{$product->slug}")->assertNotFound();
    }

    /** Слаг модели уникален только внутри марки — чужая модель не должна открываться. */
    public function test_модель_чужой_марки_не_открывается(): void
    {
        $this->camry();
        CarBrand::factory()->visible()->create(['name' => 'BMW', 'slug' => 'bmw']);

        $this->get('/catalog/bmw/camry')->assertNotFound();
    }

    public function test_фильтр_по_типу_стекла(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Лобовое АБВ', 'glass_type' => GlassType::Windshield]);
        $this->glassFor($model, ['name' => 'Заднее ГДЕ', 'glass_type' => GlassType::Rear]);

        $this->get('/catalog/toyota/camry?type=rear')
            ->assertOk()
            ->assertSee('Заднее ГДЕ')
            ->assertDontSee('Лобовое АБВ');
    }

    public function test_поиск_находит_по_еврокоду(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Стекло с кодом', 'eurocode' => '7260AGNGYV']);

        $this->get('/search?q=7260AGNGYV')
            ->assertOk()
            ->assertSee('Стекло с кодом');
    }

    public function test_поиск_находит_по_модели_и_названию(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Лобовое стекло Toyota Camry']);

        $this->get('/search?q=Camry')
            ->assertOk()
            ->assertSee('Лобовое стекло Toyota Camry')
            ->assertSee('Модели')
            ->assertSee(route('catalog.products', [$model->brand, $model]), false);
    }

    public function test_пустой_поиск_предлагает_подбор(): void
    {
        $this->get('/search?q=неттакогостеклаxyz')
            ->assertOk()
            ->assertSee('Ничего не нашлось')
            ->assertSee('Заявка на подбор')
            ->assertSee('Подбор по VIN');
    }

    /** На однобуквенный запрос нельзя отдавать весь каталог. */
    public function test_слишком_короткий_запрос_ничего_не_ищет(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Какое-то стекло']);

        $this->get('/search?q=а')
            ->assertOk()
            ->assertDontSee('Какое-то стекло');
    }

    /** Карточка без цены не должна выигрывать сортировку «сначала дешёвые». */
    public function test_карточки_без_цены_уходят_в_конец_списка(): void
    {
        $model = $this->camry();
        $this->glassFor($model, ['name' => 'Без цены', 'price_min' => null, 'price_max' => null]);
        $this->glassFor($model, ['name' => 'С ценой', 'price_min' => 20000, 'price_max' => 20000]);

        $html = $this->get('/catalog/toyota/camry')->assertOk()->getContent();

        $this->assertLessThan(
            mb_strpos($html, 'Без цены'),
            mb_strpos($html, 'С ценой'),
            'товар с ценой должен идти раньше товара без цены'
        );
    }

    public function test_подбор_с_главной_ведёт_на_страницу_модели(): void
    {
        $model = $this->camry();

        $this->post('/pick', ['brand' => $model->car_brand_id, 'model' => $model->id])
            ->assertRedirect('/catalog/toyota/camry');
    }

    public function test_подбор_с_годом_ведёт_на_страницу_модели_с_годом(): void
    {
        $model = $this->camry();

        $this->post('/pick', [
            'brand' => $model->car_brand_id,
            'model' => $model->id,
            'year' => 2018,
        ])->assertRedirect('/catalog/toyota/camry?year=2018');
    }

    public function test_подбор_без_модели_ведёт_на_страницу_марки(): void
    {
        $model = $this->camry();

        $this->post('/pick', ['brand' => $model->car_brand_id])
            ->assertRedirect('/catalog/toyota');
    }

    public function test_главная_после_подбора_показывает_стёкла_машины(): void
    {
        $model = $this->camry();
        $product = $this->glassFor($model, ['name' => 'Лобовое Camry']);

        $this->post('/pick', ['brand' => $model->car_brand_id, 'model' => $model->id, 'year' => 2018]);
        $this->get('/')
            ->assertOk()
            ->assertSee('Стёкла для Toyota Camry, 2018')
            ->assertSee('Лобовое Camry');

        $this->get('/')
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_апи_отдаёт_только_модели_с_товарами(): void
    {
        $brand = CarBrand::factory()->visible()->create(['slug' => 'toyota']);
        CarModel::factory()->visible()->create(['car_brand_id' => $brand->id, 'name' => 'Camry']);
        CarModel::factory()->create(['car_brand_id' => $brand->id, 'name' => 'Пустая']);

        $this->getJson('/api/brands/toyota/models')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Camry']);
    }

    public function test_просмотр_карточки_считается(): void
    {
        $product = Product::factory()->create(['views' => 0]);

        $this->get("/tovar/{$product->slug}")->assertOk();

        $this->assertSame(1, $product->fresh()->views);
    }

    public function test_карточка_отдаёт_разметку_товара_для_поиска(): void
    {
        $product = Product::factory()->create(['name' => 'Лобовое стекло Camry', 'eurocode' => '7260AGNGYV']);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('"@type":"Product"', false)
            ->assertSee('7260AGNGYV')
            ->assertSee('PreOrder');
    }
}
