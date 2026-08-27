<?php

namespace App\Catalog;

/**
 * Разбор VIN.
 *
 * Что из VIN достаётся бесплатно и надёжно:
 *   знаки 1-3  — завод-изготовитель, отсюда марка и страна;
 *   знак  10   — год выпуска.
 *
 * Чего не достаётся: модель. Знаки 4-8 каждый производитель кодирует по-своему
 * и таблиц не публикует — «Camry» из VIN без платного сервиса не получить.
 * Поэтому VIN здесь сужает выбор до марки и года, а модель покупатель называет
 * сам. Сам VIN уходит в заявку: менеджер пробьёт его точно.
 */
readonly class Vin
{
    public const LENGTH = 17;

    /**
     * I, O и Q в VIN не используются — их исключили, чтобы не путать
     * с единицей и нулём. Их присутствие означает опечатку, а не редкий VIN.
     */
    private const ALPHABET = '/^[A-HJ-NPR-Z0-9]{17}$/';

    /** Знак 10 -> год. Буквы повторяются с шагом 30 лет, отсюда неоднозначность. */
    private const YEAR_CODES = 'ABCDEFGHJKLMNPRSTVWXY123456789';

    private function __construct(public string $value) {}

    /**
     * Приводит к виду, в котором VIN сравнивают, и проверяет.
     *
     * Пробелы и дефисы убираем молча: их ставят при переписывании из ПТС,
     * и отказывать из-за них — значит спорить с покупателем о форматировании.
     */
    public static function parse(?string $raw): ?self
    {
        $value = mb_strtoupper(preg_replace('/[\s\-]+/u', '', (string) $raw));

        return preg_match(self::ALPHABET, $value) === 1 ? new self($value) : null;
    }

    /** Понятно, чем именно плох введённый VIN, — иначе подсказка бесполезна. */
    public static function explainFailure(?string $raw): ?string
    {
        $value = mb_strtoupper(preg_replace('/[\s\-]+/u', '', (string) $raw));

        if ($value === '') {
            return null;
        }

        if (preg_match('/[^A-Z0-9]/u', $value) === 1) {
            return 'В VIN только латинские буквы и цифры. Похоже, часть символов набрана кириллицей.';
        }

        if (preg_match('/[IOQ]/u', $value) === 1) {
            return 'Буквы I, O и Q в VIN не встречаются — скорее всего, это цифры 1 и 0.';
        }

        $length = mb_strlen($value);

        if ($length !== self::LENGTH) {
            return "В VIN ровно 17 знаков, а здесь {$length}.";
        }

        return 'Не похоже на VIN. Проверьте по документам на машину.';
    }

    public function wmi(): string
    {
        return mb_substr($this->value, 0, 3);
    }

    /**
     * Справочник заводов без обращения к контейнеру: у значения-объекта
     * не должно быть зависимости от того, поднято ли приложение.
     * Класс без состояния, держать один экземпляр достаточно.
     */
    private static function directory(): WmiDirectory
    {
        static $directory = null;

        return $directory ??= new WmiDirectory;
    }

    public function brand(): ?string
    {
        return self::directory()->brand($this->wmi());
    }

    public function country(): ?string
    {
        return self::directory()->country($this->wmi());
    }

    /**
     * Год выпуска.
     *
     * Буквенные коды повторяются каждые 30 лет: S — это и 1995, и 2025.
     * Берём свежее прочтение, если оно ещё не в будущем; иначе остаётся
     * единственное возможное — старое.
     */
    public function year(): ?int
    {
        $position = mb_strpos(self::YEAR_CODES, mb_substr($this->value, 9, 1));

        if ($position === false) {
            return null;
        }

        $recent = $this->recentYear($position);

        return $recent <= $this->latestPlausibleYear() ? $recent : $recent - 30;
    }

    /**
     * Можно ли спутать год с тем, что на 30 лет раньше.
     *
     * У цифр (2001-2009) неоднозначности нет вовсе. У букв она есть только
     * тогда, когда свежее прочтение ещё не наступило: код Y читается как 2030,
     * то есть сегодня это заведомо 2000, и спрашивать не о чем.
     */
    public function yearIsAmbiguous(): bool
    {
        $position = mb_strpos(self::YEAR_CODES, mb_substr($this->value, 9, 1));

        if ($position === false || $position >= 21) {
            return false;   // цифра: 2001-2009 и никак иначе
        }

        return $this->recentYear($position) <= $this->latestPlausibleYear();
    }

    /** Второй возможный год — на 30 лет раньше. Показывается как уточнение. */
    public function alternativeYear(): ?int
    {
        return $this->yearIsAmbiguous() ? $this->year() - 30 : null;
    }

    /**
     * Позиции 0-20 — буквы A..Y, они же 2010-2030.
     * Позиции 21-29 — цифры 1..9, они же 2001-2009 и не повторяются.
     */
    private function recentYear(int $position): int
    {
        return $position >= 21 ? 2001 + ($position - 21) : 2010 + $position;
    }

    /** Новые машины продаются следующим годом уже осенью. */
    private function latestPlausibleYear(): int
    {
        return (int) date('Y') + 1;
    }

    public function masked(): string
    {
        return mb_substr($this->value, 0, 11).'••••••';
    }
}
