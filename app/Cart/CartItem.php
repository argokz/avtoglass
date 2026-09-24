<?php

namespace App\Cart;

use App\Models\Offer;
use App\Models\Product;

/**
 * Строка корзины.
 *
 * Цену берём из выбранного предложения, иначе из минимума карточки.
 * Зафиксировать её нужно ровно один раз — в момент оформления, это OrderItem.
 */
readonly class CartItem
{
    public function __construct(
        public Product $product,
        public int $quantity,
        public ?Offer $offer = null,
    ) {}

    public function price(): float
    {
        $buyer = текущийПокупатель();

        if ($this->offer !== null) {
            return (float) ($this->offer->sellingPrice($buyer) ?? 0);
        }

        return (float) ($this->product->sellingPriceMin($buyer) ?? 0);
    }

    public function subtotal(): float
    {
        return $this->price() * $this->quantity;
    }

    /** Цену знаем не всегда: часть позиций идёт «по запросу». */
    public function hasPrice(): bool
    {
        return $this->price() > 0;
    }
}
