<?php

namespace Tests\Feature;

use App\Catalog\PublishingGuard;
use App\Enums\GlassType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Последний рубеж перед витриной. Ошибка здесь означает, что покупатель
 * увидит стекло за десять тенге, поэтому правила закреплены тестами.
 */
class PublishingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function guard(): PublishingGuard
    {
        return new PublishingGuard;
    }

    public function test_карточка_без_цены_снимается_с_витрины(): void
    {
        $product = Product::factory()->priced(null)->create();

        $this->guard()->apply();

        $product->refresh();

        $this->assertFalse($product->is_published);
        $this->assertTrue($product->needs_review);
    }

    public function test_нулевая_цена_снимается_с_витрины(): void
    {
        $product = Product::factory()->priced(0)->create();

        $this->guard()->apply();

        $this->assertFalse($product->fresh()->is_published);
    }

    /** Заднее стекло за 10 ₸ — мусор в прайсе, а не акция. */
    public function test_неправдоподобно_дешёвое_стекло_снимается(): void
    {
        $product = Product::factory()->type(GlassType::Rear)->priced(10)->create();

        $this->guard()->apply();

        $product->refresh();

        $this->assertFalse($product->is_published);
        $this->assertTrue($product->needs_review);
    }

    /** А вот скотч и лезвия столько и стоят — расходники под правило не подпадают. */
    public function test_дешёвый_расходник_остаётся_на_витрине(): void
    {
        $product = Product::factory()->type(GlassType::Consumable)->priced(380)->create();

        $this->guard()->apply();

        $this->assertTrue($product->fresh()->is_published);
    }

    public function test_услуга_с_низкой_ценой_остаётся(): void
    {
        $product = Product::factory()->type(GlassType::Service)->priced(500)->create();

        $this->guard()->apply();

        $this->assertTrue($product->fresh()->is_published);
    }

    public function test_нормальное_стекло_не_трогается(): void
    {
        $product = Product::factory()->type(GlassType::Windshield)->priced(27000)->create();

        $this->guard()->apply();

        $product->refresh();

        $this->assertTrue($product->is_published);
        $this->assertFalse($product->needs_review);
    }

    /** Самое дешёвое настоящее стекло в выгрузке — около 3000 ₸, порог его не задевает. */
    public function test_самое_дешёвое_настоящее_стекло_проходит(): void
    {
        $product = Product::factory()->type(GlassType::Side)->priced(3080)->create();

        $this->guard()->apply();

        $this->assertTrue($product->fresh()->is_published);
    }

    public function test_возвращает_число_снятых_карточек(): void
    {
        Product::factory()->count(2)->priced(null)->create();
        Product::factory()->type(GlassType::Rear)->priced(5)->create();
        Product::factory()->priced(30000)->create();

        $this->assertSame(3, $this->guard()->apply());
    }

    /** Уже снятые карточки повторно не считаются — иначе отчёт врёт. */
    public function test_повторный_запуск_ничего_не_снимает(): void
    {
        Product::factory()->priced(null)->create();

        $this->guard()->apply();

        $this->assertSame(0, $this->guard()->apply());
    }
}
