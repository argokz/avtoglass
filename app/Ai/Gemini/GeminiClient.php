<?php

namespace App\Ai\Gemini;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Минимальный клиент Gemini generateContent.
 *
 * Свой, а не SDK: нам нужны две вещи, которые SDK не даёт из коробки, —
 * перебор пар «ключ + модель» по кругу при 429 и function calling
 * произвольным массивом. Оба — пара десятков строк на честном HTTP.
 */
class GeminiClient
{
    public function __construct(
        private readonly GeminiRotator $rotator,
        private readonly string $endpoint,
    ) {}

    /**
     * Один шаг диалога. Возвращает parts первого кандидата:
     * list<array{text?:string}|array{functionCall:array{name:string,args:array}}>
     *
     * @param  list<array{role:string,parts:list<array>}>  $contents
     * @param  list<array{name:string,description:string,parameters:array}>  $functions
     *
     * @throws GeminiUnavailableException Все связки «ключ+модель» на паузе или без ответа 429 не обошлись
     */
    public function generate(array $contents, ?string $system = null, array $functions = []): array
    {
        $candidates = $this->rotator->candidates();

        if ($candidates === []) {
            throw new GeminiUnavailableException('Все модели и ключи Gemini на паузе после лимита');
        }

        $payload = ['contents' => $contents];

        if ($system !== null && $system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($functions !== []) {
            $payload['tools'] = [['functionDeclarations' => $functions]];
        }

        foreach ($candidates as $combo) {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->post("{$this->endpoint}/{$combo['model']}:generateContent?key={$combo['key']}", $payload);

            if ($response->status() === 503) {
                // Перегрузка модели — минуты, не час. Не сажаем связку на длинную паузу.
                $this->rotator->markExhausted($combo, 2);

                continue;
            }

            if ($this->shouldSkip($response)) {
                $this->rotator->markExhausted($combo);

                continue;
            }

            if ($response->failed()) {
                // Не лимит и не снятая модель — битый ключ или отказ Google.
                // Крутиться дальше бессмысленно, чинить всё равно человеку.
                throw new RuntimeException(
                    "Gemini {$combo['model']}: HTTP {$response->status()} — ".mb_substr($response->body(), 0, 300)
                );
            }

            $this->rotator->markWorking($combo);

            return $response->json('candidates.0.content.parts') ?? [];
        }

        throw new GeminiUnavailableException('Лимит исчерпан на всех ключах и моделях');
    }

    /**
     * 429 — лимит связки. 404 — модель сняли или новым ключам её не дают.
     * 503 обрабатывается отдельно: короткая пауза, не час.
     * Эти ответы лечатся следующей моделью в круге, а не падением чата.
     */
    private function shouldSkip(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->status() === 429 || $response->status() === 404) {
            return true;
        }

        if (! $response->failed()) {
            return false;
        }

        $body = mb_strtolower($response->body());

        return str_contains($body, 'not found')
            || str_contains($body, 'not available')
            || str_contains($body, 'no longer available');
    }
}
