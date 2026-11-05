<?php

namespace Tests\Feature;

use App\Domain\Catalog\Availability;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Срок «когда будет стекло»: только то, что знаем, без выдуманных дней. */
class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $offer = [], array $supplier = []): Offer
    {
        return Offer::factory()->create([
            'product_id' => Product::factory()->create(['is_published' => true])->id,
            'supplier_id' => Supplier::factory()->create($supplier)->id,
            'stock_qty' => 0,
            ...$offer,
        ])->load('supplier');
    }

    public function test_без_срока_честно_зовём_менеджера(): void
    {
        $срок = Availability::forOffer($this->offer([], ['default_lead_days' => 0]));

        $this->assertSame(Availability::UNKNOWN, $срок->state);
        $this->assertSame('Под заказ · срок назовёт менеджер', $срок->label());
    }

    public function test_срок_поставщика_даёт_дату(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 10:00', 'Asia/Almaty');
        $срок = Availability::forOffer($this->offer([], ['default_lead_days' => 3]), null, $now);

        $this->assertSame(3, $срок->days);
        $this->assertSame('2026-11-08', $срок->readyBy->toDateString());
        $this->assertStringContainsString('3 дн., ориентировочно к 8 ноября', $срок->label());
    }

    public function test_срок_предложения_важнее_срока_поставщика(): void
    {
        $this->assertSame(7, Availability::forOffer($this->offer(['lead_days' => 7], ['default_lead_days' => 3]))->days);
    }

    public function test_остаток_показывается_только_с_включённым_наличием(): void
    {
        $offer = $this->offer(['stock_qty' => 4]);

        settings()->save(['display_stock' => true]);
        $this->assertSame(Availability::IN_STOCK, Availability::forOffer($offer)->state);

        settings()->save(['display_stock' => false]);
        $this->assertNotSame(Availability::IN_STOCK, Availability::forOffer($offer)->state);
    }

    public function test_карточка_товара_показывает_срок(): void
    {
        $offer = $this->offer(['price_retail' => 30000], ['default_lead_days' => 2]);

        $this->get(route('catalog.product', $offer->product))
            ->assertOk()
            ->assertSee('Под заказ · 2 дн., ориентировочно к', false);
    }

    public function test_массив_из_поиска_восстанавливается(): void
    {
        $срок = Availability::lead(5, CarbonImmutable::parse('2026-11-05', 'Asia/Almaty'));

        $this->assertEquals($срок->toArray(), Availability::fromArray($срок->toArray())->toArray());
    }
}
