<?php

namespace App\Ai\Gemini;

use Illuminate\Support\Facades\Cache;

/**
 * Круг по связкам «ключ + модель».
 *
 * Бесплатные лимиты Gemini кончаются быстро, поэтому держим несколько
 * моделей и до двух ключей. Ответ 429 означает «эта связка устала»:
 * запоминаем её в кеше на время паузы и идём к следующей по кругу.
 * После паузы запись в кеше истекает сама — связка снова в строю.
 * Последняя рабочая связка запоминается без срока: следующий запрос
 * начинается с неё, а не с начала списка.
 */
class GeminiRotator
{
    private const CACHE_PREFIX = 'gemini:rotator:';

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $models
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $models,
        private readonly int $cooldownMinutes,
    ) {}

    /**
     * Живые связки, начиная с последней рабочей и дальше по кругу.
     *
     * @return list<array{key:string,model:string}>
     */
    public function candidates(): array
    {
        $all = $this->all();
        $fresh = array_values(array_filter($all, fn (array $combo) => ! $this->isExhausted($combo)));

        if ($fresh === []) {
            return [];
        }

        $current = Cache::get(self::CACHE_PREFIX.'current');

        // Крутим список так, чтобы первой шла запомненная связка.
        $start = 0;
        foreach ($fresh as $i => $combo) {
            if ($this->fingerprint($combo) === $current) {
                $start = $i;
                break;
            }
        }

        return array_merge(array_slice($fresh, $start), array_slice($fresh, 0, $start));
    }

    /** Связка отдыхает: 429 — cooldown из настроек, 503 — короткая пауза. */
    public function markExhausted(array $combo, ?int $minutes = null): void
    {
        Cache::put(
            self::CACHE_PREFIX.'exhausted:'.$this->fingerprint($combo),
            true,
            now()->addMinutes(max(1, $minutes ?? $this->cooldownMinutes)),
        );
    }

    /** Успешный ответ: следующий запрос начинаем с этой связки. */
    public function markWorking(array $combo): void
    {
        Cache::forever(self::CACHE_PREFIX.'current', $this->fingerprint($combo));
    }

    public function isExhausted(array $combo): bool
    {
        return Cache::has(self::CACHE_PREFIX.'exhausted:'.$this->fingerprint($combo));
    }

    /** Все связки устали — честно скажем клиенту, а не будем крутить дальше. */
    public function allExhausted(): bool
    {
        return $this->candidates() === [];
    }

    /** @return list<array{key:string,model:string}> */
    private function all(): array
    {
        $combos = [];

        foreach ($this->keys as $key) {
            foreach ($this->models as $model) {
                $combos[] = ['key' => $key, 'model' => $model];
            }
        }

        return $combos;
    }

    private function fingerprint(array $combo): string
    {
        // Ключ в открытом виде в кеш не кладём — там логи и дампы.
        return md5($combo['key']).':'.$combo['model'];
    }
}
