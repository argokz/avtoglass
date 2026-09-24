<?php

namespace App\Cart;

use App\Enums\FulfillmentType;

/**
 * Итог корзины под выбранный способ получения.
 *
 * На карточке уже показывают «стекло + работа». В корзине та же сумма
 * должна совпадать — иначе человек думает, что цена выросла на оформлении.
 */
readonly class CartQuote
{
    public function __construct(
        public float $glass,
        public float $works,
        public float $visit,
        public bool $hasItemsWithoutPrice,
    ) {}

    public function total(): float
    {
        return $this->glass + $this->works + $this->visit;
    }

    /** @return array{glass:float,works:float,visit:float,total:float,hasItemsWithoutPrice:bool} */
    public function toArray(): array
    {
        return [
            'glass' => $this->glass,
            'works' => $this->works,
            'visit' => $this->visit,
            'total' => $this->total(),
            'hasItemsWithoutPrice' => $this->hasItemsWithoutPrice,
        ];
    }

    public static function forCart(Cart $cart, FulfillmentType $fulfillment): self
    {
        $glass = $cart->total();
        $works = 0.0;
        $visit = 0.0;

        if (settings()->enabled('service_prices') && $fulfillment->includesInstallation()) {
            foreach ($cart->items() as $item) {
                $service = \App\Models\Service::defaultFor($item->product->glass_type);

                if ($service === null) {
                    continue;
                }

                $works += (float) $service->price * $item->quantity;
            }
        }

        if (settings()->enabled('service_prices') && $fulfillment === FulfillmentType::Visit) {
            $fee = \App\Models\Service::visitFee();
            $visit = $fee ? (float) $fee->price : 0.0;
        }

        return new self($glass, $works, $visit, $cart->hasItemsWithoutPrice());
    }
}
