<?php

namespace Tests\Unit;

use App\Catalog\ModelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Все имена здесь взяты из справочника, собранного по выгрузке «БД 02.08.26.xls».
 */
class ModelNormalizerTest extends TestCase
{
    private ModelNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ModelNormalizer;
    }

    #[DataProvider('generationCases')]
    public function test_выносит_поколение_из_имени(string $brand, string $raw, string $name, ?string $generation): void
    {
        $result = $this->normalizer->normalize($brand, $raw);

        $this->assertSame($name, $result->name, "имя модели для «{$raw}»");
        $this->assertSame($generation, $result->generation, "поколение для «{$raw}»");
    }

    public static function generationCases(): array
    {
        return [
            'римская цифра' => ['Volkswagen', 'Golf II', 'Golf', 'II'],
            'римская длинная' => ['Volkswagen', 'Jetta VII', 'Jetta', 'VII'],
            'индекс кузова' => ['BMW', '5-SERIES E39', '5', 'E39'],
            'числовая серия' => ['Toyota', 'CAMRY 40', 'Camry', '40'],
            'две части' => ['Volkswagen', 'Golf IV A4', 'Golf', 'IV A4'],
            'индекс VW' => ['Volkswagen', 'Caddy III 2K', 'Caddy', 'III 2K'],
            'Land Cruiser' => ['Toyota', 'Land Cruiser 200', 'Land Cruiser', '200'],
            'без поколения' => ['Toyota', 'Highlander', 'Highlander', null],
        ];
    }

    /**
     * Числовые модели трогать нельзя: у «100» и «2110» нет слова с буквой,
     * из которого можно было бы сделать имя, — само число и есть модель.
     */
    #[DataProvider('numericModelCases')]
    public function test_числовая_модель_не_превращается_в_поколение(string $brand, string $raw): void
    {
        $result = $this->normalizer->normalize($brand, $raw);

        $this->assertSame($raw, $result->name);
        $this->assertNull($result->generation);
    }

    public static function numericModelCases(): array
    {
        return [
            ['Audi', '100'],
            ['ВАЗ', '2110'],
            ['Mazda', '3'],
            ['BMW', '5'],
        ];
    }

    #[DataProvider('junkCases')]
    public function test_убирает_мусор_из_имени(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize('Тест', $raw)->name);
    }

    public static function junkCases(): array
    {
        return [
            'серия XYG' => ['SONATA RED', 'Sonata'],
            'серия и кузов' => ['X5 RED', 'X5'],
            'тип стекла' => ['Focus II перед', 'Focus'],
            'опция' => ['Sonata датчик запотевания', 'Sonata'],
            'камера' => ['X7 мал. камера', 'X7'],
            'тонировка' => ['PASSAT B5 ЗЛСР', 'Passat'],
            'год в хвосте' => ['Mondeo IV 2007', 'Mondeo'],
            'год с дефисом' => ['CR-V 06-', 'CR-V'],
            'кузов через точку' => ['A8 .седан', 'A8'],
            'слово series' => ['7 series', '7'],
            'обратная кавычка' => ['5-SERIE E34` RED', '5'],
            'хвост еврокода' => ['Chaser Hardtop Agngy', 'Chaser'],
            'состояние' => ['Corona Exiv Б/У', 'Corona Exiv'],
            'плёнка' => ['Camry 30 Хамелеон', 'Camry'],
        ];
    }

    /**
     * Вторую машину пишут через слэш с пробелом, а индекс кузова — слитно.
     * Этим они и отличаются.
     */
    #[DataProvider('slashCases')]
    public function test_отрезает_вторую_машину_но_не_индекс_кузова(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize('Toyota', $raw)->name);
    }

    public static function slashCases(): array
    {
        return [
            'вторая машина' => ['Camry /SCEPTER WGN', 'Camry'],
            'без пробела' => ['ESTIMA/PREVIA', 'Estima'],
            'пробел после' => ['Avensis/ Caldina', 'Avensis'],
            'с дефисом' => ['HI-LUX/FORTUNER', 'HI-LUX'],
            'индекс кузова остаётся' => ['Corolla KE140/KE150', 'Corolla KE140/KE150'],
        ];
    }

    /**
     * Кириллица внутри кода — частая беда прайсов: «Cresta х100» и «Cresta X100»
     * для базы разные строки, а машина одна.
     */
    #[DataProvider('homoglyphCases')]
    public function test_приводит_кириллицу_в_кодах_к_латинице(string $raw, string $name, ?string $generation): void
    {
        $result = $this->normalizer->normalize('Toyota', $raw);

        $this->assertSame($name, $result->name);
        $this->assertSame($generation, $result->generation);
    }

    public static function homoglyphCases(): array
    {
        return [
            'кириллическая х' => ['Cresta х100', 'Cresta', 'x100'],
            'кириллическая С' => ['A6 С6', 'A6', 'C6'],
        ];
    }

    /** Слово целиком по-русски трогать нельзя — там нет кода. */
    public function test_русское_имя_не_транслитерируется(): void
    {
        $this->assertSame('Веста', $this->normalizer->normalize('ВАЗ', 'Веста')->name);
    }

    #[DataProvider('bodyCases')]
    public function test_убирает_обозначение_кузова(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize('Toyota', $raw)->name);
    }

    public static function bodyCases(): array
    {
        return [
            ['Camry Gracia WGN', 'Camry Gracia'],
            ['Crown Hardtop', 'Crown'],
            ['Avensis WGN', 'Avensis'],
        ];
    }

    /** «Corolla 7» — седьмое поколение, а не отдельная модель. */
    public function test_однозначное_поколение_выносится(): void
    {
        $result = $this->normalizer->normalize('Toyota', 'Corolla 7');

        $this->assertSame('Corolla', $result->name);
        $this->assertSame('7', $result->generation);
    }

    /** «CAMRY» кричит, «CR-V» и «RAV4» — официальное написание. */
    #[DataProvider('caseCases')]
    public function test_приводит_регистр_не_ломая_индексы(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize('Тест', $raw)->name);
    }

    public static function caseCases(): array
    {
        return [
            ['CAMRY', 'Camry'],
            ['PALISADE', 'Palisade'],
            ['CR-V', 'CR-V'],
            ['RAV4', 'RAV4'],
            ['X5', 'X5'],
            ['BMW', 'BMW'],
        ];
    }

    /** Псевдоним важнее любых правил: его завёл человек, и он знает лучше. */
    public function test_псевдоним_перебивает_разбор(): void
    {
        $normalizer = new ModelNormalizer([
            'Mercedes-Benz|W140, S-Class' => ['name' => 'S-Class', 'generation' => 'W140'],
        ]);

        $result = $normalizer->normalize('Mercedes-Benz', 'W140, S-Class');

        $this->assertSame('S-Class', $result->name);
        $this->assertSame('W140', $result->generation);
        $this->assertTrue($result->fromAlias);
    }

    public function test_псевдоним_нечувствителен_к_регистру(): void
    {
        $normalizer = new ModelNormalizer([
            'Toyota|LANDCR' => ['name' => 'Land Cruiser', 'generation' => null],
        ]);

        $this->assertSame('Land Cruiser', $normalizer->normalize('toyota', 'landcr')->name);
    }

    /** Если после чистки не осталось ничего, лучше некрасивое имя, чем пустое. */
    public function test_не_возвращает_пустое_имя(): void
    {
        $result = $this->normalizer->normalize('Тест', 'RED');

        $this->assertNotSame('', $result->name);
    }
}
