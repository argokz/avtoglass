<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Scraper\AutoGlassScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Выдумку вычистили из полей карточки, но она осталась на картинке: чертёж
 * рисовался от запасных 1400×800 и подписывал их как размер стекла. Поле
 * пустое, а покупатель читает цифру с рисунка — и заказывает по ней.
 *
 * Стрелка с подписью читается как измерение. Нет измерения — нет стрелки.
 */
class DrawingHonestyTest extends TestCase
{
    use RefreshDatabase;

    private function чертёж(Product $product): string
    {
        $scraper = new AutoGlassScraper;
        $метод = new \ReflectionMethod($scraper, 'fetchOrGenerateImage');
        $метод->setAccessible(true);

        return (string) $метод->invoke($scraper, $product, [
            'width_mm' => $product->width_mm,
            'height_mm' => $product->height_mm,
        ]);
    }

    public function test_без_габаритов_размеров_на_чертеже_нет(): void
    {
        $product = Product::factory()->create(['width_mm' => null, 'height_mm' => null]);

        $svg = $this->чертёж($product);

        $this->assertStringNotContainsString('W:', $svg);
        $this->assertStringNotContainsString('H:', $svg);
        $this->assertStringNotContainsString('1400', $svg, 'запасной размер не должен попадать на картинку');
    }

    public function test_настоящие_габариты_на_чертеже_остаются(): void
    {
        $product = Product::factory()->create(['width_mm' => 1420, 'height_mm' => 687]);

        $svg = $this->чертёж($product);

        $this->assertStringContainsString('W: 1420 мм', $svg);
        $this->assertStringContainsString('H: 687 мм', $svg);
    }

    /** Известна одна сторона — подписываем одну, вторую не выдумываем. */
    public function test_известна_только_ширина(): void
    {
        $product = Product::factory()->create(['width_mm' => 1420, 'height_mm' => null]);

        $svg = $this->чертёж($product);

        $this->assertStringContainsString('W: 1420 мм', $svg);
        $this->assertStringNotContainsString('H:', $svg);
    }

    public function test_чертёж_остаётся_валидным_svg(): void
    {
        foreach ([[null, null], [1420, 687], [1420, null], [null, 687]] as [$w, $h]) {
            $svg = $this->чертёж(Product::factory()->create(['width_mm' => $w, 'height_mm' => $h]));

            libxml_use_internal_errors(true);
            $this->assertNotFalse(
                simplexml_load_string($svg),
                "Чертёж сломан при габаритах {$w}×{$h}"
            );
        }
    }

    /**
     * Схема — это SVG, и сохранённая с именем .png она приезжает браузеру
     * как image/png: файл есть, а в каталоге пустая рамка на каждой карточке.
     */
    public function test_схема_сохраняется_с_расширением_svg(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['image_path' => null]);

        (new AutoGlassScraper)->applyDataToProduct($product, [
            'image_binary' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ]);

        $this->assertStringEndsWith('.svg', (string) $product->image_path);
    }

    /** Настоящий PNG остаётся PNG: расширение идёт от содержимого. */
    public function test_настоящий_png_сохраняется_как_png(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create(['image_path' => null]);

        (new AutoGlassScraper)->applyDataToProduct($product, [
            'image_binary' => "\x89PNG\r\n\x1a\n".str_repeat('x', 64),
        ]);

        $this->assertStringEndsWith('.png', (string) $product->image_path);
    }
}
