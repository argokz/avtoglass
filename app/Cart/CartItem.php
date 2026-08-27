<?php

namespace App\Cart;

use App\Models\Product;

/**
 * Строка корзины.
 *
 * Цену не храним, а берём из карточки при каждом показе. Прайсы поставщиков
 * переливаются еженедельно, и корзина, запомнившая цену прошлой недели,
 * пообещала бы клиенту то, чего уже нет. Зафиксировать цену нужно ровно
 * один раз — в момент оформления заказа, и это делает OrderItem.
 */
readonly class CartItem
{
    public function __construct(
        public Product $product,
        public int $quantity,
    ) {}

    public function price(): float
    {
        return (float) ($this->product->price_min ?? 0);
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
