<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\CustomerPriceCalculator;
use App\Domain\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CustomerPriceCalculatorTest extends TestCase
{
    public function test_personal_discount_requires_approval_and_uses_unit_rounding_with_cost_floor(): void
    {
        $calculator = new CustomerPriceCalculator;
        $retail = Money::fromDecimal('33.35');
        $cost = Money::fromDecimal('20.00');
        $this->assertSame('33.35', $calculator->unitPrice($retail, $cost, false, 1000)->decimal());
        $this->assertSame('90.06', $calculator->unitPrice($retail, $cost, true, 1000)->times(3)->decimal());
        $this->assertSame('90.09', $calculator->unitPrice($retail, Money::fromDecimal('30.03'), true, 1000)->times(3)->decimal());
        $this->assertSame('40.00', $calculator->unitPrice($retail, Money::fromDecimal('40.00'), true, 0)->decimal());
    }

    public function test_unknown_and_invalid_product_prices_remain_unknown(): void
    {
        $calculator = new CustomerPriceCalculator;
        foreach ([null, Money::fromMinor(0), Money::fromMinor(-100)] as $retail) {
            $this->assertNull($calculator->unitPrice($retail, Money::fromMinor(100), false));
            $this->assertNull($calculator->unitPrice($retail, Money::fromMinor(100), true, 1000));
        }
    }

    public function test_discount_cannot_exceed_existing_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CustomerPriceCalculator)->unitPrice(Money::fromMinor(100), null, true, 4001);
    }
}
