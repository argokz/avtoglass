<?php

declare(strict_types=1);

namespace App\Ai;

final class PromptPrivacy
{
    public static function text(string $text): string
    {
        $facts = AssistantOrderIntent::fromMessages([['role' => 'user', 'text' => $text]], settings()->cities());
        foreach (['name', 'phone', 'address'] as $field) {
            if (isset($facts[$field]) && mb_strlen((string) $facts[$field]) >= 3) {
                $text = str_replace((string) $facts[$field], '['.$field.' заполнено]', $text);
            }
        }
        $text = preg_replace('/(?:\+?7|8)[\s()-]*(?:\d[\s()-]*){9}\d/u', '[контакт заполнен]', $text) ?? $text;

        return preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email заполнен]', $text) ?? $text;
    }
}
