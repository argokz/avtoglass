<?php

namespace App\Cart;

use App\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Корзина покупателя, живущая в сессии.
 *
 * В сессии лежат только id товаров и количества — сами товары каждый раз
 * достаются из базы. Это не оптимизация наоборот, а защита: между «положил
 * в корзину» и «оформил» карточка может уехать с витрины или подорожать,
 * и корзина обязана это показать, а не хранить снимок недельной давности.
 */
class Cart
{
    private const KEY = 'cart';

    /** Стекло объёмное: заказ на сотню штук — это ошибка ввода, а не опт. */
    public const MAX_QUANTITY = 20;

    public function __construct(private readonly Session $session) {}

    public function add(Product $product, int $quantity = 1): void
    {
        if (! $product->is_published) {
            return;
        }

        $lines = $this->lines();
        $current = $lines[$product->id] ?? 0;

        $lines[$product->id] = $this->clamp($current + $quantity);

        $this->save($lines);
    }

    public function setQuantity(Product $product, int $quantity): void
    {
        $lines = $this->lines();

        if ($quantity <= 0) {
            unset($lines[$product->id]);
        } else {
            $lines[$product->id] = $this->clamp($quantity);
        }

        $this->save($lines);
    }

    public function remove(Product $product): void
    {
        $lines = $this->lines();
        unset($lines[$product->id]);

        $this->save($lines);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    /**
     * Содержимое корзины. Товары, ушедшие с витрины, тихо выпадают —
     * и из выдачи, и из самой сессии: предлагать оформить то, чего уже
     * нет в каталоге, нельзя.
     *
     * @return Collection<int,CartItem>
     */
    public function items(): Collection
    {
        $lines = $this->lines();

        if ($lines === []) {
            return collect();
        }

        $products = Product::whereIn('id', array_keys($lines))
            ->where('is_published', true)
            ->get()
            ->keyBy('id');

        $alive = array_intersect_key($lines, $products->all());

        if (count($alive) !== count($lines)) {
            $this->save($alive);
        }

        return collect($alive)
            ->map(fn (int $qty, int $id) => new CartItem($products[$id], $qty))
            ->values();
    }

    public function count(): int
    {
        return array_sum($this->lines());
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    public function total(): float
    {
        return (float) $this->items()->sum(fn (CartItem $item) => $item->subtotal());
    }

    /** Есть ли позиции без цены — по таким менеджер называет сумму сам. */
    public function hasItemsWithoutPrice(): bool
    {
        return $this->items()->contains(fn (CartItem $item) => ! $item->hasPrice());
    }

    /** @return array<int,int> id товара => количество */
    private function lines(): array
    {
        return array_filter((array) $this->session->get(self::KEY, []));
    }

    private function save(array $lines): void
    {
        if ($lines === []) {
            $this->session->forget(self::KEY);

            return;
        }

        $this->session->put(self::KEY, $lines);
    }

    private function clamp(int $quantity): int
    {
        return max(1, min(self::MAX_QUANTITY, $quantity));
    }
}
