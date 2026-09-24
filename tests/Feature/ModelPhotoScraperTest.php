<?php

namespace Tests\Feature;

use App\Models\CarBrand;
use App\Models\CarModel;
use App\Services\Scraper\InternetMediaScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Фото моделей в каталоге.
 *
 * Снимок нужен у каждой модели со стёклами, не только у «популярных».
 * Силуэт SVG на диск не пишем: на витрине он и так рисуется, если фото нет.
 */
class ModelPhotoScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_качает_фото_у_непопулярной_модели_с_одним_стеклом(): void
    {
        $model = $this->model(['products_count' => 1, 'is_popular' => false]);

        $this->fakeWikipediaPhoto();

        $saved = (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertSame(1, $saved);
        $this->assertStringEndsWith('.jpg', (string) $model->fresh()->image_path);
        Storage::disk('public')->assertExists($model->fresh()->image_path);
    }

    public function test_перекачивает_svg_заглушку(): void
    {
        Storage::disk('public')->put('models/toyota-camry.svg', '<svg></svg>');

        $model = $this->model([
            'products_count' => 1,
            'image_path' => 'models/toyota-camry.svg',
        ]);

        $this->fakeWikipediaPhoto();

        (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertStringEndsWith('.jpg', (string) $model->fresh()->image_path);
    }

    public function test_не_пишет_силуэт_если_снимка_нет(): void
    {
        $model = $this->model(['products_count' => 1]);

        Http::fake(['*' => Http::response(['query' => ['pages' => []]])]);

        $saved = (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertSame(0, $saved);
        $this->assertNull($model->fresh()->image_path);
    }

    public function test_снимает_ссылку_на_svg_если_снимок_не_нашёлся(): void
    {
        $model = $this->model([
            'products_count' => 1,
            'image_path' => 'models/toyota-camry.svg',
        ]);

        Http::fake(['*' => Http::response(['query' => ['pages' => []]])]);

        (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertNull($model->fresh()->image_path);
    }

    public function test_не_трогает_уже_скачанный_jpg(): void
    {
        $jpeg = $this->jpeg();
        Storage::disk('public')->put('models/toyota-camry.jpg', $jpeg);

        $model = $this->model([
            'products_count' => 3,
            'image_path' => 'models/toyota-camry.jpg',
        ]);

        Http::fake(['*' => Http::response('should-not-be-called', 500)]);

        $saved = (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertSame(0, $saved);
        $this->assertSame('models/toyota-camry.jpg', $model->fresh()->image_path);
        Http::assertNothingSent();
    }

    public function test_модели_без_стёкол_не_качает(): void
    {
        $this->model(['products_count' => 0]);

        Http::fake(['*' => Http::response('should-not-be-called', 500)]);

        $this->assertSame(0, (new InternetMediaScraper)->scrapeModelPhotos());
        Http::assertNothingSent();
    }

    public function test_ищет_hilux_по_имени_hi_lux_из_прайса(): void
    {
        $brand = CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota']);
        $model = CarModel::factory()->create([
            'car_brand_id' => $brand->id,
            'name' => 'HI-LUX',
            'slug' => 'hi-lux',
            'products_count' => 5,
            'image_path' => null,
        ]);

        $jpeg = $this->jpeg();

        Http::fake(function (Request $request) use ($jpeg) {
            $url = urldecode($request->url());

            if (str_contains($url, 'wikipedia.org') && str_contains($url, 'api.php')) {
                if (! str_contains($url, 'Hilux')) {
                    return Http::response(['query' => ['pages' => []]]);
                }

                return Http::response([
                    'query' => [
                        'pages' => [[
                            'title' => 'Toyota Hilux',
                            'thumbnail' => [
                                'source' => 'https://upload.wikimedia.org/wikipedia/commons/hilux.jpg',
                            ],
                        ]],
                    ],
                ]);
            }

            if (str_contains($url, 'upload.wikimedia.org')) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['query' => ['pages' => []]]);
        });

        (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertStringEndsWith('.jpg', (string) $model->fresh()->image_path);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'Hilux'));
    }

    public function test_ищет_s_class_по_шасси_из_прайса(): void
    {
        $brand = CarBrand::factory()->visible()->create(['name' => 'Mercedes-Benz', 'slug' => 'mercedes-benz']);
        $model = CarModel::factory()->create([
            'car_brand_id' => $brand->id,
            'name' => 'W221, S-Class',
            'slug' => 'w221-s-class',
            'products_count' => 3,
            'image_path' => null,
        ]);

        $this->fakeWikipediaPhoto('Mercedes-Benz S-Class');

        (new InternetMediaScraper)->scrapeModelPhotos();

        $this->assertStringEndsWith('.jpg', (string) $model->fresh()->image_path);
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'S-Class'));
    }

    private function model(array $state = []): CarModel
    {
        $brand = CarBrand::factory()->visible()->create(['name' => 'Toyota', 'slug' => 'toyota']);

        return CarModel::factory()->create(array_merge([
            'car_brand_id' => $brand->id,
            'name' => 'Camry',
            'slug' => 'camry',
            'products_count' => 1,
            'is_popular' => false,
            'image_path' => null,
        ], $state));
    }

    private function fakeWikipediaPhoto(string $title = 'Toyota Camry'): void
    {
        $jpeg = $this->jpeg();

        Http::fake(function (Request $request) use ($jpeg, $title) {
            $url = $request->url();

            if (str_contains($url, 'wikipedia.org') && str_contains($url, 'api.php')) {
                return Http::response([
                    'query' => [
                        'pages' => [[
                            'title' => $title,
                            'thumbnail' => [
                                'source' => 'https://upload.wikimedia.org/wikipedia/commons/camry.jpg',
                            ],
                        ]],
                    ],
                ]);
            }

            if (str_contains($url, 'upload.wikimedia.org')) {
                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['query' => ['pages' => []]]);
        });
    }

    private function jpeg(): string
    {
        return "\xFF\xD8\xFF\xE0".str_repeat('A', 3000);
    }
}
