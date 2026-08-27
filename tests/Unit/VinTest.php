<?php

namespace Tests\Unit;

use App\Catalog\Vin;
use PHPUnit\Framework\TestCase;

/**
 * Разбор VIN.
 *
 * Главное свойство — не выдумывать. VIN честно содержит завод-изготовитель
 * и год; модель в нём закодирована по-своему у каждого производителя и без
 * платного каталога не читается. Всё, что здесь проверяется, — что мы
 * достаём ровно первое и не притворяемся, будто достали второе.
 */
class VinTest extends TestCase
{
    // ------------------------------------------------------------------
    // разбор строки
    // ------------------------------------------------------------------

    public function test_нормальный_vin_разбирается(): void
    {
        $this->assertSame('JTDBR32E560012345', Vin::parse('JTDBR32E560012345')?->value);
    }

    /** Из документов переписывают как придётся — регистр и пробелы не повод отказывать. */
    public function test_регистр_пробелы_и_дефисы_не_мешают(): void
    {
        $this->assertSame('JTDBR32E560012345', Vin::parse(' jtdbr32e56-0012345 ')?->value);
    }

    public function test_короткий_и_длинный_отклоняются(): void
    {
        $this->assertNull(Vin::parse('JTDBR32E56001234'));
        $this->assertNull(Vin::parse('JTDBR32E5600123456'));
        $this->assertNull(Vin::parse(null));
    }

    /** I, O и Q исключены из стандарта, чтобы не путать с 1 и 0. */
    public function test_запрещённые_буквы_отклоняются(): void
    {
        $this->assertNull(Vin::parse('JTDBR32E56001234I'));
        $this->assertNull(Vin::parse('JTDBR32E56001234O'));
        $this->assertNull(Vin::parse('JTDBR32E56001234Q'));
    }

    // ------------------------------------------------------------------
    // подсказки при ошибке
    // ------------------------------------------------------------------

    /** Самая частая ошибка ввода: кириллическая «А» вместо латинской. */
    public function test_кириллица_названа_своим_именем(): void
    {
        $this->assertStringContainsString(
            'кириллиц',
            (string) Vin::explainFailure('ЈТDBR32E560012345')
        );
    }

    public function test_про_запрещённые_буквы_сказано_прямо(): void
    {
        $this->assertStringContainsString('I, O и Q', (string) Vin::explainFailure('JTDBR32E56001234I'));
    }

    public function test_про_длину_сказано_с_числом(): void
    {
        $this->assertStringContainsString('здесь 3', (string) Vin::explainFailure('ABC'));
    }

    /** На пустое поле ругаться незачем — человек ещё не начал. */
    public function test_на_пустую_строку_подсказки_нет(): void
    {
        $this->assertNull(Vin::explainFailure(''));
        $this->assertNull(Vin::explainFailure('   '));
    }

    // ------------------------------------------------------------------
    // год выпуска
    // ------------------------------------------------------------------

    /** Цифра в десятом знаке — это 2001-2009 и никак иначе. */
    public function test_цифровой_код_года_однозначен(): void
    {
        $vin = Vin::parse('JTDBR32E560012345');

        $this->assertSame(2006, $vin->year());
        $this->assertFalse($vin->yearIsAmbiguous());
        $this->assertNull($vin->alternativeYear());
    }

    /** Буквы повторяются каждые 30 лет: D — это и 2013, и 1983. */
    public function test_буквенный_код_года_неоднозначен(): void
    {
        $vin = Vin::parse('WBA3B5C50DF123456');

        $this->assertSame(2013, $vin->year());
        $this->assertTrue($vin->yearIsAmbiguous());
        $this->assertSame(1983, $vin->alternativeYear());
    }

    /**
     * Когда свежее прочтение ещё не наступило, неоднозначности нет:
     * код Y это 2030, то есть сегодня он может значить только 2000.
     */
    public function test_будущий_год_читается_как_прошлый_и_однозначен(): void
    {
        $vin = Vin::parse('WBA3B5C50YF123456');

        $this->assertSame(2000, $vin->year());
        $this->assertFalse($vin->yearIsAmbiguous());
    }

    public function test_недопустимый_знак_года_даёт_null(): void
    {
        // 0 в позицию года стандарт не допускает
        $this->assertNull(Vin::parse('WBA3B5C500F123456')->year());
    }

    // ------------------------------------------------------------------
    // марка и страна
    // ------------------------------------------------------------------

    public function test_марка_определяется_по_заводу(): void
    {
        $this->assertSame('Toyota', Vin::parse('JTDBR32E560012345')->brand());
        $this->assertSame('BMW', Vin::parse('WBA3B5C50DF123456')->brand());
        $this->assertSame('Hyundai', Vin::parse('KMHD35LE7EU123456')->brand());
        $this->assertSame('ВАЗ', Vin::parse('XTA21099012345678')->brand());
    }

    /** Более длинный код важнее короткого: JT это Toyota, а JTJ — уже Lexus. */
    public function test_трёхзначный_код_перебивает_двузначный(): void
    {
        $this->assertSame('Lexus', Vin::parse('JTJBM7FX5F5123456')->brand());
        $this->assertSame('Toyota', Vin::parse('JTDBR32E560012345')->brand());
    }

    /**
     * Справочник заводов заведомо неполный. Незнакомый код должен дать null,
     * а не приблизительную догадку: неверная марка уводит покупателя
     * в чужой каталог и выглядит как работающая функция.
     */
    public function test_незнакомый_завод_марки_не_придумывает(): void
    {
        $this->assertNull(Vin::parse('QQQ3B5C50DF123456'));      // Q запрещена
        $this->assertNull(Vin::parse('ABC3B5C50DF123456')->brand());
    }

    /** Страна читается по первому знаку и полезна даже без марки. */
    public function test_страна_читается_даже_у_незнакомого_завода(): void
    {
        $this->assertSame('Германия', Vin::parse('WZZ3B5C50DF123456')->country());
        $this->assertSame('Япония', Vin::parse('JZZ3B5C50DF123456')->country());
    }
}
