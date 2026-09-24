<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

final class CanonicalJson
{
    public static function encode(array $data): string
    {
        return json_encode(self::normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalize(...), $value);
        }
        if ($value === null || is_string($value) || is_bool($value) || is_int($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Canonical payloads require explicit scalar values; floats and objects are not supported.');
    }
}
