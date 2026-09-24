<?php

namespace Tests\Feature;

use App\Catalog\ModelNormalizer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Одна буква, набранная не в той раскладке, делает товар ненаходимым:
 * покупатель ищет «CLK» латиницей, а в карточке стоит кириллическая С.
 * Карточка есть, показов нет, продажи нет.
 */
class NameLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('раскладка')]
    public function test_кириллица_примесью_выправляется(string $было, string $станет): void
    {
        $this->assertSame($станет, (new ModelNormalizer)->fixLayout($было));
    }

    /** @return array<string, array{string, string}> */
    public static function раскладка(): array
    {
        return [
            'С в CLK' => ['Лобовое стекло Mercedes-Benz СLK-Class Coupe', 'Лобовое стекло Mercedes-Benz CLK-Class Coupe'],
            'А в KA' => ['Лобовое стекло Ford KА 1996–2008', 'Лобовое стекло Ford KA 1996–2008'],
            'Н в HR/V' => ['Honda НR/V S2H', 'Honda HR/V S2H'],
            'Т в 21-CT' => ['Haval JOLION 21-CТ', 'Haval JOLION 21-CT'],
        ];
    }

    /**
     * Русские слова в названии — «Лобовое стекло», «обогрев щёток» — трогать
     * нельзя. Правило смотрит на смесь внутри слова, а не на строку целиком.
     */
    #[DataProvider('нетронутые')]
    public function test_русский_текст_остаётся_русским(string $name): void
    {
        $this->assertSame($name, (new ModelNormalizer)->fixLayout($name));
    }

    /** @return array<string, array{string}> */
    public static function нетронутые(): array
    {
        return [
            'чистая латиница' => ['Лобовое стекло Toyota Camry XV40'],
            'русские пометки' => ['Лобовое стекло ВАЗ 2110 (обогрев щёток)'],
            'русское слово с латинской примесью' => ['ГАЗ NEXTстекло'],
            'только русский' => ['Лобовое стекло Лада Веста'],
        ];
    }

    public function test_команда_правит_названия_товаров(): void
    {
        $кривой = Product::factory()->create(['name' => 'Лобовое стекло Mercedes-Benz СLK-Class']);
        $целый = Product::factory()->create(['name' => 'Лобовое стекло Toyota Camry']);

        $this->artisan('catalog:fix-layout --apply')->assertSuccessful();

        $this->assertSame('Лобовое стекло Mercedes-Benz CLK-Class', $кривой->fresh()->name);
        $this->assertSame('Лобовое стекло Toyota Camry', $целый->fresh()->name);
    }

    /** Без --apply команда только показывает. */
    public function test_без_apply_названия_не_меняются(): void
    {
        $product = Product::factory()->create(['name' => 'Лобовое стекло Ford KА']);

        $this->artisan('catalog:fix-layout')->assertSuccessful();

        $this->assertSame('Лобовое стекло Ford KА', $product->fresh()->name);
    }

    /**
     * Правка идёт по имени и только по имени: у карточки уже есть фотографии,
     * остатки и позиции в заказах, и пересборка каталога их снесла бы.
     */
    public function test_правка_не_трогает_ничего_кроме_имени(): void
    {
        $product = Product::factory()->create([
            'name' => 'Лобовое стекло Ford KА',
            'slug' => 'lobovoe-steklo-ford-ka',
        ]);
        $было = $product->only(['id', 'slug', 'car_model_id', 'price']);

        $this->artisan('catalog:fix-layout --apply');

        $this->assertSame($было, $product->fresh()->only(['id', 'slug', 'car_model_id', 'price']));
    }
}
