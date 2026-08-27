<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Cart\Cart;
use App\Enums\FulfillmentType;
use App\Enums\GlassType;
use App\Enums\OrderSource;
use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Service;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Работы в заказе.
 *
 * До сих пор клиент видел цену стекла и платил на пятнадцать тысяч больше:
 * установка не считалась нигде, и узнавал он о ней от менеджера. Из-за этого же
 * в отчётах не было маржи — выручка складывалась из одного стекла.
 */
class ServicePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ServiceSeeder::class);
    }

    private function glass(GlassType $type, float $price = 30000): Product
    {
        $product = Product::factory()->create([
            'glass_type' => $type->value,
            'price_min' => $price,
            'price_max' => $price,
        ]);

        Offer::factory()->price((int) $price)->create(['product_id' => $product->id]);

        return $product;
    }

    private function order(Product $product, FulfillmentType $fulfillment)
    {
        return app(CreateOrder::class)->handle(
            ['name' => 'Данил', 'phone' => '77015550001', 'fulfillment' => $fulfillment->value],
            OrderSource::ProductPage,
            $product,
        );
    }

    // ------------------------------------------------------------------
    // прайс на работы
    // ------------------------------------------------------------------

    public function test_каждому_стеклу_своя_установка(): void
    {
        $this->assertSame(15000.0, (float) Service::defaultFor(GlassType::Windshield)->price);
        $this->assertSame(12000.0, (float) Service::defaultFor(GlassType::Rear)->price);
        $this->assertSame(6000.0, (float) Service::defaultFor(GlassType::Side)->price);
    }

    /**
     * Калибровка обязана быть в прайсе, но предлагать её всем нельзя:
     * она нужна только там, где за стеклом камера.
     */
    public function test_калибровка_по_умолчанию_не_предлагается(): void
    {
        $calibration = Service::where('slug', 'kalibrovka-adas')->sole();

        $this->assertFalse($calibration->is_default);
        $this->assertNotSame($calibration->id, Service::defaultFor(GlassType::Windshield)->id);
    }

    /** Расходникам установка не положена — их просто отдают. */
    public function test_у_расходников_установки_нет(): void
    {
        $this->assertNull(Service::defaultFor(GlassType::Consumable));
    }

    // ------------------------------------------------------------------
    // витрина
    // ------------------------------------------------------------------

    public function test_на_карточке_видно_цену_с_работой(): void
    {
        $product = $this->glass(GlassType::Windshield, 30000);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('Установка лобового стекла')
            ->assertSee('15 000')
            ->assertSee('45 000');   // стекло плюс работа
    }

    /** Про камеру молчать нельзя: калибровка иначе всплывёт счётом на месте. */
    public function test_про_калибровку_предупреждают_заранее(): void
    {
        $product = $this->glass(GlassType::Windshield);
        $product->update(['camera' => true]);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('За стеклом камера');
    }

    public function test_без_камеры_про_калибровку_не_пишут(): void
    {
        $this->get(route('catalog.product', $this->glass(GlassType::Windshield)))
            ->assertOk()
            ->assertDontSee('За стеклом камера');
    }

    // ------------------------------------------------------------------
    // заказ
    // ------------------------------------------------------------------

    public function test_замена_в_сервисе_добавляет_работу_в_заказ(): void
    {
        $order = $this->order($this->glass(GlassType::Windshield, 30000), FulfillmentType::Installation);

        $this->assertSame(2, $order->items()->count());
        $this->assertSame(45000.0, (float) $order->fresh()->total_amount);
    }

    /** Клиент забирает стекло сам — работы в заказе быть не должно. */
    public function test_самовывоз_работу_не_добавляет(): void
    {
        $order = $this->order($this->glass(GlassType::Windshield, 30000), FulfillmentType::Pickup);

        $this->assertSame(1, $order->items()->count());
        $this->assertSame(30000.0, (float) $order->fresh()->total_amount);
    }

    public function test_доставка_работу_не_добавляет(): void
    {
        $order = $this->order($this->glass(GlassType::Windshield, 30000), FulfillmentType::Delivery);

        $this->assertSame(1, $order->items()->count());
    }

    /** Боковому — своя цена, а не цена лобового. */
    public function test_боковому_стеклу_добавляется_своя_работа(): void
    {
        $order = $this->order($this->glass(GlassType::Side, 8000), FulfillmentType::Installation);

        $this->assertSame(14000.0, (float) $order->fresh()->total_amount);
        $this->assertNotNull($order->items()->where('name', 'Установка бокового стекла')->first());
    }

    /** Заказ на два стекла — две работы, каждая своя. */
    public function test_в_корзине_каждому_стеклу_своя_работа(): void
    {
        $brand = CarBrand::factory()->create();
        $model = CarModel::factory()->create(['car_brand_id' => $brand->id]);

        $cart = app(Cart::class);

        foreach ([[GlassType::Windshield, 30000], [GlassType::Side, 8000]] as [$type, $price]) {
            $product = $this->glass($type, $price);
            Fitment::factory()->create(['product_id' => $product->id, 'car_model_id' => $model->id]);
            $cart->add($product);
        }

        $order = app(CreateOrder::class)->fromCart(
            ['name' => 'Данил', 'phone' => '77015550002', 'fulfillment' => FulfillmentType::Installation->value],
            $cart,
        );

        // 30000 + 15000 + 8000 + 6000
        $this->assertSame(59000.0, (float) $order->fresh()->total_amount);
        $this->assertSame(4, $order->items()->count());
    }

    // ------------------------------------------------------------------
    // гибкость
    // ------------------------------------------------------------------

    /**
     * Ради этого работа и вынесена в отдельный прайс: цена меняется в одном
     * месте и сразу верна на всей витрине — карточек этого типа тысячи.
     */
    public function test_смена_цены_работы_меняет_сумму_новых_заказов(): void
    {
        Service::where('slug', 'ustanovka-lobovogo')->update(['price' => 18000]);

        $order = $this->order($this->glass(GlassType::Windshield, 30000), FulfillmentType::Installation);

        $this->assertSame(48000.0, (float) $order->fresh()->total_amount);
    }

    /** Выключенную работу больше не предлагают. */
    public function test_выключенная_работа_в_заказ_не_попадает(): void
    {
        Service::where('slug', 'ustanovka-lobovogo')->update(['is_active' => false]);

        $order = $this->order($this->glass(GlassType::Windshield, 30000), FulfillmentType::Installation);

        $this->assertSame(1, $order->items()->count());
    }
}
