<?php

namespace App\Catalog;

/**
 * Ищет в названии модели следы неудачного разбора прайса.
 *
 * Прайсы приходят строкой вида «Toyota 4RUNNER а/ст лоб.», и когда разбор
 * отрезает не там, хвост атрибута остаётся в имени модели: «4RUNNER а»,
 * «2110 п.ш», «Land Cruiser Оригин». Покупатель видит это в подборе.
 *
 * Класс только помечает подозрительное и называет причину. Чинит человек:
 * правильное имя знает он, а не эвристика, и молча переименовывать модель,
 * за которой висят товары, — худший из вариантов.
 */
class ModelNameAudit
{
    /**
     * Обрывки, которые встречались в прайсах поставщиков. Список ведём руками:
     * добавить строку сюда дешевле, чем ослабить общие правила и нахватать
     * ложных срабатываний на живых названиях.
     */
    private const ОБРЫВКИ = [
        'оригин', 'сре', 'ери', 'куз', 'тн', 'бел', 'оп', 'зел', 'сер',
    ];

    /** Марки, у которых кириллица в названии модели — норма. */
    private const РУССКИЕ_МАРКИ = [
        'ВАЗ', 'ГАЗ', 'УАЗ', 'ПАЗ', 'КАМАЗ', 'МАЗ', 'ЗИЛ', 'ЗАЗ', 'ЛуАЗ',
        'Москвич', 'ИЖ', 'КрАЗ', 'ЛиАЗ', 'НефАЗ', 'УралАЗ', 'Лада', 'Lada',
    ];

    public function reason(string $name, ?string $brand = null): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return 'пустое название';
        }

        foreach (preg_split('/\s+/u', $name) as $word) {
            if ($reason = $this->wordReason($word)) {
                return $reason;
            }
        }

        return $this->tailReason($name, $brand);
    }

    public function suspicious(string $name, ?string $brand = null): bool
    {
        return $this->reason($name, $brand) !== null;
    }

    private function wordReason(string $word): ?string
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $word) ?? '';

        /*
         * Латиница и кириллица в одном слове — это «KА» и «Sedс», где часть
         * букв набрана не в той раскладке. Живых названий с таким смешением
         * не бывает, поэтому правило срабатывает и на русских марках тоже.
         */
        if (preg_match('/\p{Latin}/u', $letters) && preg_match('/\p{Cyrillic}/u', $letters)) {
            return "в слове «{$word}» смешаны латиница и кириллица";
        }

        // «п.ш», «.о», «.Д.з» — сокращение атрибута, а не часть имени.
        if (preg_match('/\p{Cyrillic}/u', $word) && str_contains($word, '.')) {
            return "«{$word}» похоже на сокращение атрибута";
        }

        return null;
    }

    private function tailReason(string $name, ?string $brand): ?string
    {
        if ($this->русскаяМарка($brand)) {
            return null;
        }

        $words = preg_split('/\s+/u', $name);
        $last = mb_strtolower(end($words));

        if (in_array($last, self::ОБРЫВКИ, true)) {
            return "хвост «{$last}» — обрывок атрибута";
        }

        /*
         * Короткое кириллическое слово в хвосте латинского названия: «4RUNNER а»,
         * «Lancer 10 куз». Одна-две буквы кириллицей после латиницы — всегда
         * остаток разбора, целого слова в них не бывает.
         */
        if (count($words) > 1
            && preg_match('/^\p{Cyrillic}{1,2}$/u', $last)
            && preg_match('/\p{Latin}|\d/u', $name)) {
            return "хвост «{$last}» похож на обрывок";
        }

        return null;
    }

    private function русскаяМарка(?string $brand): bool
    {
        if ($brand === null) {
            return false;
        }

        foreach (self::РУССКИЕ_МАРКИ as $known) {
            if (mb_strtolower($brand) === mb_strtolower($known)) {
                return true;
            }
        }

        return false;
    }
}
