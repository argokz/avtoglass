<?php

namespace App\Catalog;

/**
 * ISO-2 коды стран по русским названиям.
 *
 * Страна у поставщика хранится текстом («Япония»), а для приоритета
 * отечественного товара нужен код: сравнивать строки на равенство
 * «Казахстану» нельзя — написание в прайсах гуляет.
 */
class Countries
{
    /** @var array<string,string> */
    private const ISO = [
        'казахстан' => 'KZ',
        'россия' => 'RU',
        'китай' => 'CN',
        'япония' => 'JP',
        'польша' => 'PL',
        'великобритания' => 'GB',
        'корея' => 'KR',
        'южная корея' => 'KR',
        'германия' => 'DE',
        'сша' => 'US',
        'турция' => 'TR',
        'бельгия' => 'BE',
    ];

    public static function isoFor(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return self::ISO[mb_strtolower(trim($name))] ?? null;
    }
}
