<?php

namespace Tests\Unit;

use App\Enums\GlassType;
use App\Services\PriceList\NameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Все строки здесь — настоящие, из выгрузки «БД 02.08.26.xls».
 * Придуманных примеров в этом файле быть не должно: парсер ломается
 * не на синтетике, а на том, как поставщики пишут на самом деле.
 */
class NameParserTest extends TestCase
{
    private NameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new NameParser;
    }

    /** Пять диалектов — по одному на поставщика, разбор не должен зависеть от порядка слов. */
    #[DataProvider('dialectCases')]
    public function test_разбирает_все_диалекты(string $raw, array $expected): void
    {
        $parsed = $this->parser->parse($raw);

        foreach ($expected as $field => $value) {
            $actual = $field === 'glassType' ? $parsed->glassType?->value : $parsed->{$field};

            $this->assertSame($value, $actual, "поле {$field} в строке: {$raw}");
        }
    }

    public static function dialectCases(): array
    {
        return [
            'КМК' => [
                'Toyota "Highlander" III U50 5D Utility \'2013- #8445 ЗП ТЗ ДД камера (обогрев щеток) КМК',
                [
                    'supplier' => 'КМК',
                    'brand' => 'Toyota',
                    'model' => 'Highlander',
                    'generation' => 'III',
                    'yearFrom' => 2013,
                    'internalCode' => '8445',
                    'glassType' => 'windshield',
                ],
            ],
            'SL' => [
                'Стекло ветровое Renault Megane II 2002-07 з/п ТЗ 7260AGNGYV SL',
                [
                    'supplier' => 'SL',
                    'brand' => 'Renault',
                    'yearFrom' => 2002,
                    'yearTo' => 2007,
                    'eurocode' => '7260AGNGYV',
                    'glassType' => 'windshield',
                ],
            ],
            'AGC' => [
                '4174521 4187AGRCMUV 7900207A HYUND PALISADE 21-СТ ВЕТР ЗЛ+АК+КАМ+HUD AGC',
                [
                    'supplier' => 'AGC',
                    'model' => 'PALISADE',
                    'yearFrom' => 2021,
                    'glassType' => 'windshield',
                    'tint' => 'green',
                ],
            ],
            'XYG' => [
                'TOYOTA CAMRY 70 SEDAN 2018- Solar-X RED ДД (полный обогрев) XYG',
                [
                    'supplier' => 'XYG',
                    'brand' => 'Toyota',
                    'yearFrom' => 2018,
                    'bodyType' => 'sedan',
                    'glassType' => 'windshield',
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Регрессии: каждый тест ниже — реальный дефект, найденный на выгрузке
    // ------------------------------------------------------------------

    /** Сокращения AGC слипаются через "+" и раньше уезжали в название модели. */
    #[DataProvider('dirtyModelCases')]
    public function test_атрибуты_не_попадают_в_название_модели(string $raw, string $model): void
    {
        $this->assertSame($model, $this->parser->parse($raw)->model);
    }

    public static function dirtyModelCases(): array
    {
        return [
            ['4029093 4514ACLBL 6960300 ГАЗ 3302 СТ ВЕТРОВОЕ БЕСЦВ+КАНТ+ЗП AGC', '3302'],
            ['3038AGNBLV CHEVROLET COBALT 11- СТ ВЕТР ЗЛГЛ+VIN AGC', 'COBALT'],
            ['4034860 8394AGABLAHZ 6968311A TOYOTA LANDCR 09-СТ ВЕТР ЗЛГЛ+АНТ+ЭО+ИНК AGC', 'LANDCR'],
            ['TOYOTA CAMRY 18-СТ ВЕТР ЗЛГЛ+ЭОдв+ДД+ИНК AGC', 'CAMRY'],
            ['4029999 4502AGSBL1B 6961723 ВАЗ 2108 СТ ВЕТРОВОЕ ЗЛ OVERTIN+ЗП+КАНТ AGC', '2108'],
            // габариты без скобок, третьим числом идёт толщина
            ['4029207 4501AGNBL 6960514 ВАЗ 2110 СТ ВЕТРОВОЕ ЗЛ OVERTINTED 1428x862x5,00 AGC', '2110'],
        ];
    }

    public function test_снимает_габариты_без_скобок(): void
    {
        $parsed = $this->parser->parse(
            '4029207 4501AGNBL 6960514 ВАЗ 2110 СТ ВЕТРОВОЕ ЗЛ OVERTINTED 1428x862x5,00 AGC'
        );

        $this->assertSame(1428, $parsed->widthMm);
        $this->assertSame(862, $parsed->heightMm);
    }

    public function test_артикул_поставщика_не_становится_частью_модели(): void
    {
        $parsed = $this->parser->parse('Стекло ветровое Mercedes W140, S-Class LEM-TIL-MS0659 SL');

        $this->assertStringNotContainsString('LEM', (string) $parsed->model);
    }

    /** "Б/ДЕРЖ.ЗЕР" — стекло БЕЗ кронштейна: общее правило прочитало бы наоборот. */
    public function test_различает_наличие_и_отсутствие_держателя_зеркала(): void
    {
        $with = $this->parser->parse('УАЗ 3163 СТ ВЕТРОВОЕ OVER+КАНТ+ДЕРЖ.ЗЕРК AGC');
        $without = $this->parser->parse('УАЗ 3163 СТ ВЕТРОВОЕ OVER+КАНТ+Б/ДЕРЖ.ЗЕР AGC');

        $this->assertTrue($with->option('mirror_bracket'));
        $this->assertFalse($without->option('mirror_bracket'));
    }

    /** Сторона обязана определяться: левое и правое боковое — разные товары. */
    public function test_определяет_сторону_бокового_стекла(): void
    {
        $left = $this->parser->parse('ВАЗ 2110 стекло боковое опускное левое КМК');
        $right = $this->parser->parse('ВАЗ 2110 стекло боковое опускное правое КМК');

        $this->assertSame('left', $left->position);
        $this->assertSame('right', $right->position);
        $this->assertSame(GlassType::Side, $left->glassType);
    }

    /** "правый руль" — это опция RHD, а не сторона стекла. */
    public function test_правый_руль_не_считается_стороной(): void
    {
        $parsed = $this->parser->parse('Toyota "Ipsum" стекло ветровое правый руль КМК');

        $this->assertTrue($parsed->option('rhd'));
        $this->assertNull($parsed->position);
    }

    /** ВАЗ пишут списком: одно стекло на шесть машин, а не модель с таким именем. */
    public function test_список_моделей_ваз_превращается_в_со_модели(): void
    {
        $parsed = $this->parser->parse('ВАЗ 2108,09,099,13,14,15 стекло ветровое ЗП КМК');

        $this->assertSame('2108', $parsed->model);
        // "099" не разворачиваем: это 21099, а не 2099 — угадывать нельзя
        $this->assertSame(
            ['2109', '099', '2113', '2114', '2115'],
            array_column($parsed->coModels, 'model')
        );
        $this->assertContains('Не удалось развернуть номер модели: 099', $parsed->issues);
    }

    /** BMW "3 E30" | "5D Touring" — в кавычках второй кузов, а не вторая модель. */
    public function test_кузов_в_кавычках_не_становится_со_моделью(): void
    {
        $parsed = $this->parser->parse(
            'BMW "3 E30" 2D Cpe (82-91) / 4D Sed (83-94) | "5D Touring" (88-94) \'1982-1994 #2425 ЗП КМК'
        );

        $this->assertSame([], $parsed->coModels);
    }

    /** А вот "Elantra Touring" — настоящая модель, её терять нельзя. */
    public function test_настоящая_со_модель_сохраняется(): void
    {
        $parsed = $this->parser->parse(
            'Hyundai "i30" I 5D Hbk / 5D Wagon | "Elantra Touring" (08-) 5D Wagon \'2007-2012 ЗП ТЗ ДД КМК'
        );

        $this->assertSame(['Elantra Touring'], array_column($parsed->coModels, 'model'));
    }

    /** Номера моделей выглядят как диапазон лет — год не должен их съедать. */
    public function test_номер_модели_не_читается_как_год(): void
    {
        $parsed = $this->parser->parse('ВАЗ "2101-2107" стекло ветровое КМК');

        $this->assertSame('2101-2107', $parsed->model);
        $this->assertNull($parsed->yearFrom);
    }

    /** Расходники и услуги важнее типа стекла: "молдинг лобового" — это молдинг. */
    #[DataProvider('nonGlassCases')]
    public function test_расходники_и_услуги_не_считаются_стеклом(string $raw, GlassType $type): void
    {
        $this->assertSame($type, $this->parser->parse($raw)->glassType);
    }

    public static function nonGlassCases(): array
    {
        return [
            ['Молдинг лобового стекла KIA CEED 12-18', GlassType::Consumable],
            ['Установка лобового стекла', GlassType::Service],
        ];
    }

    /** Без марки или типа стекла карточку не собрать — такая строка идёт на проверку. */
    public function test_строка_без_марки_уходит_на_ручную_проверку(): void
    {
        $parsed = $this->parser->parse('Стекло ветровое непонятно чьё 2010-2015');

        $this->assertSame('review', $parsed->status());
    }
}
