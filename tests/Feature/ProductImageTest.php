<?php

namespace Tests\Feature;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Models\Fitment;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Фотографии товаров и логотипы марок.
 *
 * Главное свойство — необязательность. Стёкла приходят из прайсов без картинок,
 * снимут их в лучшем случае для ходовых позиций, и витрина обязана выглядеть
 * законченной и без единого загруженного файла.
 */
class ProductImageTest extends TestCase
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

    private function glass(CarModel $model, array $state = []): Product
    {
        $product = Product::factory()->create($state);

        Fitment::factory()->create([
            'product_id' => $product->id,
            'car_model_id' => $model->id,
        ]);

        return $product;
    }

    // ------------------------------------------------------------------
    // адреса
    // ------------------------------------------------------------------

    public function test_без_файла_адреса_нет(): void
    {
        $this->assertNull(Product::factory()->create(['image_path' => null])->imageUrl());
        $this->assertNull(CarBrand::factory()->create(['logo_path' => null])->logoUrl());
    }

    public function test_с_файлом_адрес_ведёт_в_публичное_хранилище(): void
    {
        $product = Product::factory()->create(['image_path' => 'products/camry.jpg']);

        $this->assertStringContainsString('/storage/products/camry.jpg', $product->imageUrl());
    }

    // ------------------------------------------------------------------
    // витрина
    // ------------------------------------------------------------------

    public function test_карточка_показывает_фото_когда_оно_есть(): void
    {
        $model = $this->camry();
        $this->glass($model, ['name' => 'Лобовое Camry', 'image_path' => 'products/camry.jpg']);

        $this->get('/catalog/toyota/camry')
            ->assertOk()
            ->assertSee('/storage/products/camry.jpg', escape: false);
    }

    /** Без фото карточка не пустая: значок отвечает «лобовое или боковое». */
    public function test_без_фото_карточка_рисует_значок(): void
    {
        $model = $this->camry();
        $this->glass($model, ['name' => 'Лобовое Camry', 'image_path' => null]);

        $this->get('/catalog/toyota/camry')
            ->assertOk()
            ->assertSee('Лобовое Camry')
            ->assertDontSee('/storage/products', escape: false);
    }

    public function test_страница_товара_показывает_фото(): void
    {
        $model = $this->camry();
        $product = $this->glass($model, ['image_path' => 'products/camry.jpg']);

        $this->get(route('catalog.product', $product))
            ->assertOk()
            ->assertSee('/storage/products/camry.jpg', escape: false);
    }

    public function test_список_марок_показывает_логотип(): void
    {
        CarBrand::factory()->visible()->create([
            'name' => 'Toyota',
            'slug' => 'toyota',
            'logo_path' => 'brands/toyota.png',
        ]);

        $this->get(route('catalog.brands'))
            ->assertOk()
            ->assertSee('/storage/brands/toyota.png', escape: false);
    }

    /** Марок без логотипа сейчас большинство — страница обязана это пережить. */
    public function test_список_марок_без_логотипов_открывается(): void
    {
        CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota', 'logo_path' => null]);

        $this->get(route('catalog.brands'))
            ->assertOk()
            ->assertSee('Toyota');
    }

    public function test_фото_модели_ведёт_в_хранилище(): void
    {
        $model = $this->camry();
        $model->update(['image_path' => 'models/toyota-camry.jpg']);

        $this->assertStringContainsString('/storage/models/toyota-camry.jpg', $model->imageUrl());
    }

    /** Силуэт, сохранённый как «фото», на витрине не показываем. */
    public function test_svg_силуэт_модели_не_считается_фото(): void
    {
        $model = $this->camry();
        $model->update(['image_path' => 'models/toyota-camry.svg']);

        $this->assertFalse($model->hasPhoto());
        $this->assertNull($model->imageUrl());

        $this->get(route('catalog.models', $model->brand))
            ->assertOk()
            ->assertSee('Camry')
            ->assertDontSee('/storage/models/toyota-camry.svg', escape: false);
    }
}
