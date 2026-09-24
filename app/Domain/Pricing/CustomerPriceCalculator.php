<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/** Pure pricing rule. Unknown prices remain unknown; purchase cost is never returned separately. */
final class CustomerPriceCalculator
{
    public function unitPrice(?Money $retail, ?Money $cost, bool $wholesaleApproved, int $discountBasisPoints = 0): ?Money
    {
        if ($discountBasisPoints < 0 || $discountBasisPoints > 4_000) {
            throw new InvalidArgumentException('Wholesale discount exceeds the supported 0–40% policy.');
        }
        if ($retail === null || $retail->amountMinor <= 0) {
            return null;
        }
        if (! $wholesaleApproved) {
            return $retail;
        }
        $price = $retail->discounted($discountBasisPoints);

        return $cost !== null && $cost->amountMinor > $price->amountMinor ? $cost : $price;
    }
}
