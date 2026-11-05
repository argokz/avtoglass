<?php

declare(strict_types=1);

namespace App\Ai\Runtime;

use Illuminate\Support\Facades\Crypt;

final class MessageText
{
    public static function encode(string $text): string
    {
        return $text === '' ? '' : 'enc:v1:'.Crypt::encryptString($text);
    }

    public static function decode(string $text): string
    {
        return str_starts_with($text, 'enc:v1:') ? Crypt::decryptString(substr($text, 7)) : $text;
    }
}
