<?php

namespace Tests\Feature;

use App\Models\CarBrand;
use App\Services\Scraper\InternetMediaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Скачивание логотипов марок.
 *
 * Источники на отсутствующий файл нередко отвечают кодом 200 и обычной
 * HTML-страницей. Качалка принимала её за картинку, а расширение определяла
 * по вхождению «<svg» где угодно — и страница 404 с иконками внутри
 * сохранялась как brands/vaz.svg. На витрине это пустая рамка вместо знака,
 * и после каждой чистки она возвращалась при следующем запуске.
 */
class LogoDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function марка(): CarBrand
    {
        return CarBrand::factory()->create(['name' => 'ВАЗ', 'slug' => 'vaz', 'logo_path' => null]);
    }

    public function test_html_страница_не_сохраняется_как_логотип(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(
            '<!doctype html><html><head></head><body><svg><path/></svg>Not Found</body></html>',
            200,
        )]);

        $brand = $this->марка();
        app(InternetMediaScraper::class)->scrapeBrandLogos();

        $path = $brand->fresh()->logo_path;
        $content = Storage::disk('public')->get($path);

        // Вместо страницы — нарисованная заглушка, настоящий SVG.
        $this->assertStringStartsWith('<svg', ltrim($content));
        $this->assertStringNotContainsStringIgnoringCase('<html', $content);
    }

    public function test_настоящий_svg_сохраняется_как_есть(): void
    {
        Storage::fake('public');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
        Http::fake(['*' => Http::response($svg, 200)]);

        $brand = $this->марка();
        app(InternetMediaScraper::class)->scrapeBrandLogos();

        $path = $brand->fresh()->logo_path;

        $this->assertStringEndsWith('.svg', $path);
        $this->assertSame($svg, Storage::disk('public')->get($path));
    }

    /** PNG получает расширение .png, а не .svg по умолчанию. */
    public function test_png_получает_своё_расширение(): void
    {
        Storage::fake('public');
        $png = "\x89PNG\r\n\x1a\n".str_repeat("\0", 200);
        Http::fake(['*.svg' => Http::response('Not Found', 404), '*' => Http::response($png, 200)]);

        $brand = $this->марка();
        app(InternetMediaScraper::class)->scrapeBrandLogos();

        $this->assertStringEndsWith('.png', $brand->fresh()->logo_path);
    }
}
