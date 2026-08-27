<?php

namespace App\Catalog;

/**
 * WMI — первые три знака VIN, код завода-изготовителя.
 *
 * Справочник намеренно неполный. Записи здесь только те, в которых нет
 * сомнений: неверно определённая марка уводит покупателя в чужой каталог
 * и выглядит как работающая функция, а «марку не определили» честно
 * возвращает его к выбору руками. Поэтому спорное лучше не добавлять.
 *
 * Модель по VIN не определяется в принципе: знаки 4-8 каждый производитель
 * кодирует по-своему и таблиц не публикует. Всё, что даёт VIN бесплатно, —
 * это марка, страна сборки и год.
 */
class WmiDirectory
{
    /**
     * Ключи длиной 3 и 2 знака. Более длинный совпадает первым: JT — Toyota,
     * но JTJ — уже Lexus, и порядок проверки это решает.
     *
     * @var array<string,string>
     */
    private const BRANDS = [
        // Япония
        'JTJ' => 'Lexus',
        'JTH' => 'Lexus',
        'JH4' => 'Acura',
        'JMB' => 'Mitsubishi',
        'JMY' => 'Mitsubishi',
        'JNK' => 'Infiniti',
        'JNR' => 'Infiniti',
        'JT' => 'Toyota',
        'JN' => 'Nissan',
        'JM' => 'Mazda',
        'JH' => 'Honda',
        'JF' => 'Subaru',
        'JA' => 'Mitsubishi',
        'JS' => 'Suzuki',
        'JD' => 'Daihatsu',

        // Корея
        'KMH' => 'Hyundai',
        'KMF' => 'Hyundai',
        'KMJ' => 'Hyundai',
        'KM8' => 'Hyundai',
        'KNA' => 'Kia',
        'KNC' => 'Kia',
        'KND' => 'Kia',
        'KNE' => 'Kia',
        'KPT' => 'SsangYong',
        'KL' => 'Chevrolet',

        // Германия
        'WVW' => 'Volkswagen',
        'WVG' => 'Volkswagen',
        'WV1' => 'Volkswagen',
        'WV2' => 'Volkswagen',
        'WAU' => 'Audi',
        'WA1' => 'Audi',
        'WUA' => 'Audi',
        'WBA' => 'BMW',
        'WBS' => 'BMW',
        'WBX' => 'BMW',
        'WBY' => 'BMW',
        'WDB' => 'Mercedes-Benz',
        'WDC' => 'Mercedes-Benz',
        'WDD' => 'Mercedes-Benz',
        'WDF' => 'Mercedes-Benz',
        'W1K' => 'Mercedes-Benz',
        'W1N' => 'Mercedes-Benz',
        'W1V' => 'Mercedes-Benz',
        'W0L' => 'Opel',
        'W0V' => 'Opel',
        'WP0' => 'Porsche',
        'WP1' => 'Porsche',
        'WF0' => 'Ford',
        'WME' => 'Smart',
        'WMW' => 'Mini',

        // Европа
        'TMB' => 'Skoda',
        'TMA' => 'Hyundai',
        'VSS' => 'Seat',
        'VF1' => 'Renault',
        'VF3' => 'Peugeot',
        'VF7' => 'Citroen',
        'ZFA' => 'Fiat',
        'ZAR' => 'Alfa Romeo',
        'YV1' => 'Volvo',
        'YV4' => 'Volvo',
        'SAL' => 'Land Rover',
        'SAJ' => 'Jaguar',
        'SJN' => 'Nissan',
        'VNK' => 'Toyota',
        'NMT' => 'Toyota',

        // СНГ
        'XTA' => 'ВАЗ',
        'XTT' => 'УАЗ',
        'XTH' => 'ГАЗ',
        'XTC' => 'КАМАЗ',
        'XW8' => 'Volkswagen',
        'XW7' => 'Toyota',
        'XWB' => 'Ravon',
        'X7L' => 'Renault',
        'Z8N' => 'Nissan',
        'Z94' => 'Hyundai',

        // США
        '1G1' => 'Chevrolet',
        '1GC' => 'Chevrolet',
        '1GN' => 'Chevrolet',
        '2G1' => 'Chevrolet',
        '3GN' => 'Chevrolet',
        '1G4' => 'Buick',
        '1G6' => 'Cadillac',
        '1GY' => 'Cadillac',
        '1GK' => 'GMC',
        '1GT' => 'GMC',
        '1FA' => 'Ford',
        '1FD' => 'Ford',
        '1FM' => 'Ford',
        '1FT' => 'Ford',
        '2FM' => 'Ford',
        '3FA' => 'Ford',
        '1HG' => 'Honda',
        '2HG' => 'Honda',
        '2HK' => 'Honda',
        '5FN' => 'Honda',
        '5J6' => 'Honda',
        '19X' => 'Honda',
        '19U' => 'Acura',
        '5J8' => 'Acura',
        '2T1' => 'Toyota',
        '4T1' => 'Toyota',
        '4T3' => 'Toyota',
        '5TB' => 'Toyota',
        '5TD' => 'Toyota',
        '5TF' => 'Toyota',
        '58A' => 'Lexus',
        '1N4' => 'Nissan',
        '1N6' => 'Nissan',
        '3N1' => 'Nissan',
        '5N1' => 'Nissan',
        '5NP' => 'Hyundai',
        '5XY' => 'Hyundai',
        '3KP' => 'Kia',
        '5XX' => 'Kia',
        '4US' => 'BMW',
        '5UX' => 'BMW',
        '4JG' => 'Mercedes-Benz',
        '1C4' => 'Jeep',
        '1J4' => 'Jeep',
        '1J8' => 'Jeep',
        '1C3' => 'Chrysler',
        '2C3' => 'Chrysler',
        '1LN' => 'Lincoln',
        '5LM' => 'Lincoln',
        '5YJ' => 'Tesla',
        '7SA' => 'Tesla',

        // Китай
        'LVV' => 'Chery',
        'LFV' => 'Volkswagen',
        'LSV' => 'Volkswagen',
        'LGX' => 'BYD',
        'L6T' => 'Geely',
    ];

    /**
     * Страна сборки по первому знаку. Определяется однозначно и полезна даже
     * тогда, когда марку опознать не удалось: покупатель хотя бы видит,
     * что VIN прочитан правильно.
     *
     * @var array<string,string>
     */
    private const COUNTRIES = [
        'J' => 'Япония',
        'K' => 'Корея',
        'L' => 'Китай',
        'M' => 'Индия, Индонезия или Таиланд',
        'N' => 'Турция',
        'S' => 'Великобритания',
        'T' => 'Чехия, Венгрия или Словакия',
        'V' => 'Франция или Испания',
        'W' => 'Германия',
        'X' => 'Россия или СНГ',
        'Y' => 'Швеция или Финляндия',
        'Z' => 'Италия',
        '1' => 'США',
        '2' => 'Канада',
        '3' => 'Мексика',
        '4' => 'США',
        '5' => 'США',
        '6' => 'Австралия',
        '8' => 'Аргентина или Чили',
        '9' => 'Бразилия',
    ];

    /** Марка или null, если WMI незнаком. */
    public function brand(string $wmi): ?string
    {
        // Сначала три знака, потом два: JT это Toyota, а JTJ — уже Lexus
        return self::BRANDS[mb_substr($wmi, 0, 3)]
            ?? self::BRANDS[mb_substr($wmi, 0, 2)]
            ?? null;
    }

    public function country(string $wmi): ?string
    {
        return self::COUNTRIES[mb_substr($wmi, 0, 1)] ?? null;
    }
}
