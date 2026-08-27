<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Загрузка снимков и характеристик из внешнего каталога.
 *
 * Источник не зашит намеренно: открытого каталога, где по еврокоду отдаётся
 * фотография, ни один производитель не публикует. Файл компания получает сама,
 * и код обязан принять его в том виде, в каком он пришёл, — папкой снимков
 * или таблицей.
 */
class MediaImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->dir = sys_get_temp_dir().'/media-import-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    /** Однопиксельный PNG: содержимое неважно, важен факт файла. */
    private function image(string $name): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        return $path;
    }

    private function csv(string $body): string
    {
        $path = $this->dir.'/catalog.csv';
        file_put_contents($path, $body);

        return $path;
    }

    // ------------------------------------------------------------------
    // папка со снимками
    // ------------------------------------------------------------------

    public function test_снимок_прикладывается_по_еврокоду(): void
    {
        $product = Product::factory()->create(['eurocode' => '4502AGS', 'image_path' => null]);
        $this->image('4502AGS.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir, '--apply' => true])->assertSuccessful();

        $this->assertNotNull($product->fresh()->image_path);
        Storage::disk('public')->assertExists($product->fresh()->image_path);
    }

    /** «4502 ags» и «4502AGS» — один код: пробелы и регистр значения не имеют. */
    public function test_код_сопоставляется_без_учёта_регистра_и_пробелов(): void
    {
        $product = Product::factory()->create(['eurocode' => '4502 AGS', 'image_path' => null]);
        $this->image('4502ags.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir, '--apply' => true])->assertSuccessful();

        $this->assertNotNull($product->fresh()->image_path);
    }

    /** Внешний каталог может звать позицию артикулом, а не еврокодом. */
    public function test_сопоставление_идёт_и_по_артикулу(): void
    {
        $product = Product::factory()->create([
            'eurocode' => null,
            'internal_code' => 'SL-1234',
            'image_path' => null,
        ]);
        $this->image('SL-1234.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir, '--apply' => true])->assertSuccessful();

        $this->assertNotNull($product->fresh()->image_path);
    }

    /**
     * Менеджер мог заменить заводскую картинку своей фотографией —
     * внешний каталог не должен её затирать.
     */
    public function test_загруженный_снимок_не_перезаписывается(): void
    {
        $product = Product::factory()->create([
            'eurocode' => '4502AGS',
            'image_path' => 'products/moe-foto.jpg',
        ]);
        $this->image('4502AGS.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir, '--apply' => true])->assertSuccessful();

        $this->assertSame('products/moe-foto.jpg', $product->fresh()->image_path);
    }

    // ------------------------------------------------------------------
    // проверка до записи
    // ------------------------------------------------------------------

    /** Чужой каталог заметить надо до того, как он ляжет в карточки. */
    public function test_без_ключа_ничего_не_пишется(): void
    {
        $product = Product::factory()->create(['eurocode' => '4502AGS', 'image_path' => null]);
        $this->image('4502AGS.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir])
            ->expectsOutputToContain('Это только проверка')
            ->assertSuccessful();

        $this->assertNull($product->fresh()->image_path);
    }

    public function test_несошедшиеся_коды_показываются(): void
    {
        Product::factory()->create(['eurocode' => '4502AGS']);
        $this->image('9999XXX.png');

        $this->artisan('catalog:import-media', ['path' => $this->dir])
            ->expectsOutputToContain('9999XXX')
            ->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // таблица
    // ------------------------------------------------------------------

    public function test_из_таблицы_заполняются_характеристики(): void
    {
        $product = Product::factory()->create([
            'eurocode' => '4502AGS',
            'width_mm' => null,
            'height_mm' => null,
        ]);

        $path = $this->csv("eurocode,width_mm,height_mm,tint\n4502AGS,1420,687,green\n");

        $this->artisan('catalog:import-media', ['path' => $path, '--apply' => true])->assertSuccessful();

        $fresh = $product->fresh();

        $this->assertSame(1420, (int) $fresh->width_mm);
        $this->assertSame(687, (int) $fresh->height_mm);
        $this->assertSame('green', $fresh->tint);
    }

    /** Уже заполненное не трогаем — правило одно на весь каталог. */
    public function test_заполненные_поля_из_таблицы_не_переписываются(): void
    {
        $product = Product::factory()->create(['eurocode' => '4502AGS', 'width_mm' => 1400]);

        $path = $this->csv("eurocode,width_mm\n4502AGS,1420\n");

        $this->artisan('catalog:import-media', ['path' => $path, '--apply' => true])->assertSuccessful();

        $this->assertSame(1400, (int) $product->fresh()->width_mm);
    }

    public function test_таблица_без_колонки_кода_отвергается(): void
    {
        $path = $this->csv("name,width_mm\nСтекло,1420\n");

        $this->artisan('catalog:import-media', ['path' => $path])
            ->expectsOutputToContain('нет колонки с кодом')
            ->assertSuccessful();
    }

    public function test_несуществующий_путь_даёт_ошибку(): void
    {
        $this->artisan('catalog:import-media', ['path' => $this->dir.'/нет-такого'])
            ->assertFailed();
    }
}
