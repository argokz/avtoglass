<?php

namespace Tests\Feature;

use App\Enums\GlassType;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Товар, заведённый менеджером в CRM, приходит без slug — его даёт модель. */
class ProductCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_товар_без_адреса_получает_его_сам(): void
    {
        $first = Product::query()->create(['name' => 'Лобовое стекло Toyota Camry', 'glass_type' => GlassType::Windshield]);
        $second = Product::query()->create(['name' => 'Лобовое стекло Toyota Camry', 'glass_type' => GlassType::Windshield]);

        $this->assertStringStartsWith('lobovoe-steklo-toyota-camry-', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_адрес_из_сборки_каталога_не_перезаписывается(): void
    {
        $product = Product::query()->create(['name' => 'Заднее', 'slug' => 'zadnee-abc', 'glass_type' => GlassType::Rear]);

        $this->assertSame('zadnee-abc', $product->slug);
    }
}
