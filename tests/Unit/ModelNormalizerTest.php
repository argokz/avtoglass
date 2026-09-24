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
            // Индекс кузова второй машиной не считается, но и в имени модели
            // ему не место: он уезжает в поколение — см. тест ниже.
            'индекс кузова не режет модель' => ['Corolla KE140/KE150', 'Corolla'],
        ];
    }

    /**
     * Перечисление индексов кузова — это одно поколение, а не имя модели.
     *
     * Поставщики пишут пары слитно: «W210,S210» у Mercedes — седан и универсал
     * одного поколения, «E60/E61» у BMW. Пока такой хвост оставался в имени,
     * одна машина разъезжалась в справочнике на несколько карточек.
     */
    #[DataProvider('enumeratedGenerationCases')]
    public function test_перечисление_индексов_кузова_уходит_в_поколение(
        string $brand,
        string $raw,
        string $name,
        string $generation,
    ): void {
        $result = $this->normalizer->normalize($brand, $raw);

        $this->assertSame($name, $result->name);
        $this->assertSame($generation, $result->generation);
    }

    public static function enumeratedGenerationCases(): array
    {
        return [
            'через слэш' => ['BMW', '5 E60 /E61', '5', 'E60 /E61'],
            'через запятую' => ['Mercedes-Benz', 'E-Class W210,S210', 'E-Class', 'W210,S210'],
            'индекс кузова Toyota' => ['Toyota', 'Corolla KE140/KE150', 'Corolla', 'KE140/KE150'],
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

    // ------------------------------------------------------------------
    // мусор поставщика в колонке модели
    // ------------------------------------------------------------------

    /**
     * Из-за этих хвостов одна машина разъезжалась в справочнике на шесть
     * карточек: «5», «5 SER», «5 кругл», «5 квадр», «5 изм», «5 грушевид».
     */
    #[DataProvider('supplierNoiseCases')]
    public function test_шум_поставщика_в_имени_модели_убирается(
        string $brand,
        string $raw,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->normalizer->normalize($brand, $raw)->name);
    }

    public static function supplierNoiseCases(): array
    {
        return [
            // Форма фар — способ поставщика различить поколения одной машины
            'круглые фары' => ['BMW', '5 кругл', '5'],
            'квадратные фары' => ['BMW', '5 квадр', '5'],
            'грушевидные фары' => ['BMW', '5 грушевид', '5'],

            // Сокращение «серия»
            'SER без окончания' => ['BMW', '5 SER', '5'],

            // Скобочный хвост, в том числе обрезанный по длине колонки
            'индекс кузова в скобках' => ['Audi', '100 (44к)', '100'],
            'незакрытая скобка' => ['ВАЗ', 'Vesta (совмещ. конне', 'Vesta'],

            // Индекс кузова словом — число забирается вместе со словом
            'кузов словом' => ['Audi', '100 44 кузов', '100'],

            // Характеристики стекла, доехавшие до колонки модели
            'неподвижное' => ['ВАЗ', 'Vesta .непод', 'Vesta'],
            'заднее без окончания' => ['ВАЗ', 'Vesta зад', 'Vesta'],
            'кузовная удлинённая' => ['Volkswagen', 'Transporter T4 удлиненная', 'Transporter'],
            'изменённое' => ['BMW', '5 изм', '5'],
            'состояние через точку' => ['Audi', '100 Б.У', '100'],

            // Марка самого стекла, а не машины
            'марка стекла SAT' => ['Kia', 'Ceed SAT', 'Ceed'],
            'марка стекла Flexline' => ['BMW', 'X5 Flexline', 'X5'],

            // Номер артикула в хвосте
            'артикул в хвосте' => ['Volkswagen', 'Transporter T4 8537', 'Transporter'],

            // Обрывок примечания из одной кириллической буквы
            'одинокая кириллица' => ['Hyundai', 'Elantra а', 'Elantra'],

            // Индекс поколения одной латинской буквой
            'буквенный индекс Opel' => ['Opel', 'Astra H', 'Astra'],
            'буквенный индекс через слово' => ['Opel', 'Astra J', 'Astra'],
        ];
    }

    /**
     * Обратная сторона: похожие хвосты бывают настоящими машинами, и склеить
     * их куда опаснее, чем оставить лишнюю карточку. Pajero Sport — это не
     * Pajero, и стекло у них разное.
     */
    #[DataProvider('mustNotMergeCases')]
    public function test_разные_машины_не_склеиваются(string $brand, string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($brand, $raw)->name);
    }

    public static function mustNotMergeCases(): array
    {
        return [
            'Pajero Sport отдельная машина' => ['Mitsubishi', 'Pajero Sport', 'Pajero Sport'],
            'Pajero IO отдельная машина' => ['Mitsubishi', 'Pajero IO', 'Pajero IO'],
            'Corolla Verso отдельная машина' => ['Toyota', 'Corolla Verso', 'Corolla Verso'],
            'Corolla Cross отдельная машина' => ['Toyota', 'Corolla Cross', 'Corolla Cross'],
            'Vesta SW Cross отдельная машина' => ['ВАЗ', 'Vesta SW Cross', 'Vesta SW Cross'],
            'GT — комплектация, а не индекс' => ['Hyundai', 'Elantra GT', 'Elantra GT'],

            // У числовой головы одиночная буква — вариант кузова: 323F хэтчбек
            'числовая голова буквы не отдаёт' => ['Mazda', '323 F', '323 F'],

            // SL — модель Mercedes, а не марка стекла
            'SL не считается маркой стекла' => ['Mercedes-Benz', 'SL', 'SL'],
        ];
    }

    /**
     * Одна буква не в той раскладке заводила модель второй карточкой:
     * «СLK-Class» с кириллической С и «CLK-Class» с латинской жили порознь,
     * и стёкла к ним лежали в двух кучах. Покупатель находил одну.
     */
    #[DataProvider('раскладка')]
    public function test_кириллица_в_латинском_названии_выправляется(string $raw, string $ожидается): void
    {
        $this->assertSame($ожидается, (new ModelNormalizer)->normalize('Mercedes-Benz', $raw)->name);
    }

    /** @return array<string, array{string, string}> */
    public static function раскладка(): array
    {
        return [
            'С вместо C' => ['СLK-Class', 'CLK-Class'],
            'Н вместо H' => ['НR/V', 'HR/V'],
            'Х вместо X' => ['Х-Ray', 'X-Ray'],
            'А вместо A' => ['KА', 'KA'],
        ];
    }

    /**
     * Обратный случай: русское слово с латинской примесью трогать нельзя,
     * иначе «NEXTстекло» станет нечитаемым «NEXTcтeклo».
     */
    public function test_русское_слово_с_латинской_примесью_не_переводится(): void
    {
        $name = (new ModelNormalizer)->normalize('ГАЗ', 'NEXTстекло')->name;

        $this->assertStringContainsString('стекло', $name);
    }

    /** И обычное русское название остаётся русским. */
    public function test_русское_название_не_латинизируется(): void
    {
        $this->assertSame('Веста', (new ModelNormalizer)->normalize('Лада', 'Веста')->name);
    }
}
