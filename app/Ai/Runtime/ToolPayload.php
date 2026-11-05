<?php

declare(strict_types=1);

namespace App\Ai\Runtime;

final class ToolPayload
{
    public static function encode(array $data): string
    {
        return json_encode(['encrypted' => MessageText::encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
    }

    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (isset($data['encrypted'])) {
            $data = json_decode(MessageText::decode($data['encrypted']), true, 512, JSON_THROW_ON_ERROR);
        }

        return is_array($data) ? $data : [];
    }
}
