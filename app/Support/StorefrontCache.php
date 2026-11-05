<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Кэш витрины с версией вместо тегов.
 *
 * Драйвер кэша — database, тегов он не умеет. Поэтому в ключ зашита
 * версия: импорт прайса или медиа поднимает её на единицу, и все
 * витринные ключи разом устаревают. Старые записи дохнут по TTL сами.
 */
class StorefrontCache
{
    private const VERSION_KEY = 'storefront:version';

    public const TTL = 600;

    private ?int $resolvedVersion = null;

    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return Cache::remember(
            'storefront:v'.$this->version().':'.$key,
            $ttl ?? self::TTL,
            $callback,
        );
    }

    /**
     * Модели по сохранённым id. В database-кэш нельзя класть Eloquent:
     * serialize/unserialize там превращает коллекцию в кашу, и шаблон
     * падает на `$brand->id`. Идентификаторы — обычные числа.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $class
     * @param  list<int|string>  $ids
     * @param  array<int,string>  $with
     * @return Collection<int, T>
     */
    public function hydrate(string $class, array $ids, array $with = []): Collection
    {
        $ids = array_values(array_filter($ids, fn ($id) => $id !== null && $id !== ''));

        if ($ids === []) {
            return collect();
        }

        $order = array_flip($ids);

        return $class::query()
            ->with($with)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($model) => $order[$model->getKey()] ?? PHP_INT_MAX)
            ->values();
    }

    /** Импорт пересобрал каталог — вся витрина перечитывается. */
    public function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
        $this->resolvedVersion = null;
    }

    private function version(): int
    {
        return $this->resolvedVersion ??= (int) Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }
}
