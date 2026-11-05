<?php

namespace App\Catalog;

use App\Enums\GlassType;
use App\Models\PriceImportRow;

/**
 * Отпечаток карточки товара по строке прайса.
 *
 * Им пользуются сборка каталога (найти или завести карточку) и предпросмотр
 * импорта (сказать заранее, сколько карточек появится). Считать его в двух
 * местах по-разному нельзя: предпросмотр обещал бы одно, а сборка делала другое.
 */
final class PriceRowSignature
{
    /** Порядок важен: он входит в подпись. Менять — значит пересобрать все карточки. */
    public const FLAGS = [
        'sun_band', 'rain_sensor', 'camera', 'heated_wipers', 'heated_full', 'heated_rear', 'antenna',
        'hud', 'acoustic', 'atermal', 'silkscreen', 'rhd', 'mirror_bracket', 'vin_window',
    ];

    /** Набор опций к полному списку флагов, чтобы подпись была стабильной. */
    public static function options(array $raw): array
    {
        $options = [];

        foreach (self::FLAGS as $flag) {
            $options[$flag] = (bool) ($raw[$flag] ?? false);
        }

        return $options;
    }

    /**
     * Годы входят в подпись, потому что стекло для рестайлинга физически
     * другое, а вот поставщик и цена — нет: на этом и работает склейка.
     */
    public static function make(PriceImportRow $row, ?array $options = null): string
    {
        $options ??= self::options((array) $row->options);

        /*
         * У расходника без марки все остальные поля пустые, и подпись
         * получилась бы одна на все семьдесят позиций — они склеились бы
         * в один товар. Различает их собственное название.
         */
        if ($row->brand_name === null && $row->glass_type === GlassType::Consumable->value) {
            return hash('sha256', 'consumable|'.mb_strtolower(trim((string) $row->raw_name)));
        }

        $parts = [
            mb_strtolower(trim((string) $row->brand_name)),
            mb_strtolower(trim((string) $row->model_name)),
            $row->glass_type,
            // сторона обязательна в подписи: левое и правое боковое — разные товары,
            // склеить их значит отгрузить клиенту не то стекло
            $row->position,
            $row->year_from,
            $row->year_to,
            $row->body_type,
            $row->doors,
            $row->eurocode ? mb_strtolower($row->eurocode) : null,
            implode(',', array_keys(array_filter($options))),
        ];

        return hash('sha256', implode('|', $parts));
    }
}
