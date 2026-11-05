<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\CatalogFilters;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Фильтры каталога на Livewire.
 *
 * Раньше каждый фильтр перезагружал страницу целиком. Здесь проверяется,
 * что при переходе на частичное обновление ничего не потерялось из того,
 * ради чего ссылки существовали: адрес по-прежнему отражает выбор,
 * фильтры складываются друг с другом, а страница не остаётся четвёртой,
 * когда список стал короче.
 */
class CatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private CarBrand $brand;

    private CarModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota']);
        $this->model = CarModel::factory()->visible()->create([
            'car_brand_id' => $this->brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
        ]);
    }

    private function glass(string $name, array $state = [], ?int $from = null, ?int $to = null): Product
    {
        $product = Product::factory()->create(array_merge(['name' => $name], $state));

        Fitment::factory()->create([
            'product_id' => $product->id,
            'car_model_id' => $this->model->id,
            'year_from' => $from,
            'year_to' => $to,
        ]);

        return $product;
    }

    private function filters()
    {
        return Livewire::test(CatalogFilters::class, [
            'brand' => $this->brand,
            'model' => $this->model,
        ]);
    }

    // ------------------------------------------------------------------
    // отбор
    // ------------------------------------------------------------------

    public function test_без_фильтров_видно_всё(): void
    {
        $this->glass('Лобовое Camry');
        $this->glass('Заднее Camry');

        $this->filters()
            ->assertSee('Лобовое Camry')
            ->assertSee('Заднее Camry');
    }

    public function test_год_отсекает_чужое_поколение(): void
    {
        $this->glass('Лобовое Camry 40', [], 2006, 2011);
        $this->glass('Лобовое Camry 70', [], 2018, null);

        $this->filters()
            ->set('year', '2008')
            ->assertSee('Лобовое Camry 40')
            ->assertDontSee('Лобовое Camry 70');
    }

    public function test_тип_стекла_отсекает_остальное(): void
    {
        $this->glass('Лобовое Camry', ['glass_type' => 'windshield']);
        $this->glass('Заднее Camry', ['glass_type' => 'rear']);

        $this->filters()
            ->call('selectType', 'windshield')
            ->assertSee('Лобовое Camry')
            ->assertDontSee('Заднее Camry');
    }

    /** Повторный клик по выбранному типу снимает фильтр. */
    public function test_повторный_клик_по_типу_снимает_его(): void
    {
        $this->glass('Лобовое Camry', ['glass_type' => 'windshield']);
        $this->glass('Заднее Camry', ['glass_type' => 'rear']);

        $this->filters()
            ->call('selectType', 'windshield')
            ->call('selectType', 'windshield')
            ->assertSee('Заднее Camry');
    }

    public function test_наличие_переключается(): void
    {
        $this->glass('Есть на складе', ['in_stock' => true]);
        $this->glass('Под заказ', ['in_stock' => false]);

        $this->filters()
            ->call('toggleStock')
            ->assertSet('inStock', true)
            ->assertSee('Есть на складе')
            ->assertDontSee('Под заказ');
    }

    public function test_зарезервированное_стекло_не_попадает_в_наличие(): void
    {
        $free = $this->glass('Свободное');
        Offer::factory()->create(['product_id' => $free->id, 'stock_qty' => 2]);
        $free->refreshPricing();

        $held = $this->glass('Уже обещано');
        $offer = Offer::factory()->create(['product_id' => $held->id, 'stock_qty' => 1]);
        $held->refreshPricing();

        $order = Order::factory()->create(['total_amount' => 30000]);
        $order->items()->create([
            'product_id' => $held->id,
            'offer_id' => $offer->id,
            'name' => 'Уже обещано',
            'price' => 30000,
            'quantity' => 1,
        ]);
        $order->transitionTo(OrderStatus::Confirmed);

        $this->filters()
            ->call('toggleStock')
            ->assertSee('Свободное')
            ->assertDontSee('Уже обещано');
    }

    /** Фильтры складываются: выбрал год, нажал тип — год остаётся. */
    public function test_фильтры_не_сбрасывают_друг_друга(): void
    {
        $this->glass('Лобовое Camry 40', ['glass_type' => 'windshield'], 2006, 2011);
        $this->glass('Заднее Camry 40', ['glass_type' => 'rear'], 2006, 2011);
        $this->glass('Лобовое Camry 70', ['glass_type' => 'windshield'], 2018, null);

        $this->filters()
            ->set('year', '2008')
            ->call('selectType', 'windshield')
            ->assertSet('year', '2008')
            ->assertSee('Лобовое Camry 40')
            ->assertDontSee('Заднее Camry 40')
            ->assertDontSee('Лобовое Camry 70');
    }

    public function test_сброс_возвращает_всё(): void
    {
        $this->glass('Лобовое Camry 40', ['glass_type' => 'windshield'], 2006, 2011);
        $this->glass('Лобовое Camry 70', ['glass_type' => 'windshield'], 2018, null);

        $this->filters()
            ->set('year', '2008')
            ->call('resetFilters')
            ->assertSet('year', '')
            ->assertSee('Лобовое Camry 70');
    }

    public function test_камера_отсекает_стекло_без_неё(): void
    {
        $this->glass('С камерой', ['camera' => true]);
        $this->glass('Без камеры', ['camera' => false]);

        $this->filters()
            ->call('toggleOption', 'camera')
            ->assertSee('С камерой')
            ->assertDontSee('Без камеры');
    }

    // ------------------------------------------------------------------
    // марка стекла
    // ------------------------------------------------------------------

    /**
     * На одну машину китайское стекло и европейское различаются втрое —
     * покупатель выбирает именно по марке, и это второй вопрос после цены.
     */
    public function test_марка_стекла_отсекает_чужие_предложения(): void
    {
        $kmk = Supplier::factory()->create(['name' => 'КМК']);
        $agc = Supplier::factory()->create(['name' => 'AGC']);

        $cheap = $this->glass('Стекло от КМК');
        Offer::factory()->price(20000)->create(['product_id' => $cheap->id, 'supplier_id' => $kmk->id]);

        $pricey = $this->glass('Стекло от AGC');
        Offer::factory()->price(60000)->create(['product_id' => $pricey->id, 'supplier_id' => $agc->id]);

        $this->filters()
            ->set('supplierId', $kmk->id)
            ->assertSee('Стекло от КМК')
            ->assertDontSee('Стекло от AGC');
    }

    /** Карточку с несколькими поставщиками показывает любой из них. */
    public function test_карточка_видна_под_каждым_своим_поставщиком(): void
    {
        $kmk = Supplier::factory()->create(['name' => 'КМК']);
        $agc = Supplier::factory()->create(['name' => 'AGC']);

        $product = $this->glass('Стекло от двоих');
        Offer::factory()->price(20000)->create(['product_id' => $product->id, 'supplier_id' => $kmk->id]);
        Offer::factory()->price(60000)->create(['product_id' => $product->id, 'supplier_id' => $agc->id]);

        $this->filters()->set('supplierId', $kmk->id)->assertSee('Стекло от двоих');
        $this->filters()->set('supplierId', $agc->id)->assertSee('Стекло от двоих');
    }

    /** Счётчики марок считаются без учёта выбранной — иначе не вернуться. */
    public function test_список_марок_остаётся_полным_после_выбора(): void
    {
        $kmk = Supplier::factory()->create(['name' => 'КМК']);
        $agc = Supplier::factory()->create(['name' => 'AGC']);

        foreach ([$kmk, $agc] as $supplier) {
            $product = $this->glass('Стекло '.$supplier->name);
            Offer::factory()->price(20000)->create([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
            ]);
        }

        $this->filters()
            ->set('supplierId', $kmk->id)
            ->assertSee('КМК')
            ->assertSee('AGC');
    }

    // ------------------------------------------------------------------
    // защита от мусора в адресе
    // ------------------------------------------------------------------

    /** В адресную строку можно вписать что угодно — фильтр это переживает. */
    public function test_мусорный_год_из_адреса_игнорируется(): void
    {
        $this->glass('Лобовое Camry', [], 2006, 2011);

        foreach (['абв', '99', '1700', '3000'] as $garbage) {
            $this->filters()
                ->set('year', $garbage)
                ->assertSee('Лобовое Camry');
        }
    }

    // ------------------------------------------------------------------
    // страницы
    // ------------------------------------------------------------------

    /**
     * Человек на четвёртой странице выбирает год и попадает на пустую
     * четвёртую страницу нового, более короткого списка — и решает,
     * что стекла нет. Поэтому смена фильтра возвращает в начало.
     */
    public function test_смена_фильтра_возвращает_на_первую_страницу(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->glass("Стекло {$i}", [], 2006, 2011);
        }

        $this->filters()
            ->set('paginators.page', 2)
            ->set('year', '2008')
            ->assertSet('paginators.page', 1);
    }

    // ------------------------------------------------------------------
    // то, ради чего фильтры остались ссылками
    // ------------------------------------------------------------------

    /**
     * Первый ответ сервера — готовый HTML с товарами. От этого зависит
     * и поисковая выдача, и человек с выключенным JS.
     */
    public function test_страница_отдаётся_собранной_без_javascript(): void
    {
        $this->glass('Лобовое Camry 40', [], 2006, 2011);

        $this->get('/catalog/toyota/camry?year=2008')
            ->assertOk()
            ->assertSee('Лобовое Camry 40')
            ->assertSee('Какой год у машины?');
    }

    /** Состояние берётся из адреса: ссылкой на отфильтрованный список делятся. */
    public function test_фильтры_читаются_из_адресной_строки(): void
    {
        $this->glass('Лобовое Camry 40', ['glass_type' => 'windshield'], 2006, 2011);
        $this->glass('Лобовое Camry 70', ['glass_type' => 'windshield'], 2018, null);

        Livewire::withQueryParams(['year' => '2008', 'type' => 'windshield'])
            ->test(CatalogFilters::class, ['brand' => $this->brand, 'model' => $this->model])
            ->assertSet('year', '2008')
            ->assertSet('type', 'windshield')
            ->assertSee('Лобовое Camry 40')
            ->assertDontSee('Лобовое Camry 70');
    }

    /** Ссылка на карточку товара из выдачи должна вести на живой товар. */
    public function test_карточки_ведут_на_страницу_товара(): void
    {
        $product = $this->glass('Лобовое Camry');
        Offer::factory()->price(30000)->create(['product_id' => $product->id]);

        $this->filters()->assertSee(route('catalog.product', $product), escape: false);
    }

    /** Повторный заход с тем же фильтром не пересчитывает чипсы поставщиков и типов. */
    public function test_повторный_рендер_берёт_фасеты_из_кэша(): void
    {
        $product = $this->glass('Лобовое Camry');
        Offer::factory()->price(20000)->create(['product_id' => $product->id]);

        $this->filters()->assertSee('Лобовое Camry');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->filters()->assertSee('Лобовое Camry');
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('join "suppliers"', $sql);
        $this->assertStringNotContainsString('group by "glass_type"', $sql);
    }
}
