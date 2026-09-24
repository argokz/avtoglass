<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Pricing\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('decimalCases')]
    public function test_decimal_conversion_and_json_keep_integer_precision(string $input, int $minor, string $decimal): void
    {
        $money = Money::fromDecimal($input);
        $this->assertSame($minor, $money->amountMinor);
        $this->assertSame($decimal, $money->decimal());
        $this->assertSame(['amount_minor' => $minor, 'currency' => 'KZT'], json_decode(json_encode($money, JSON_THROW_ON_ERROR), true));
    }

    public static function decimalCases(): array
    {
        return [
            ['25000', 2_500_000, '25000.00'], ['33.35', 3335, '33.35'], ['0.1', 10, '0.10'],
            ['-0.01', -1, '-0.01'], ['-0.00', 0, '0.00'], ['0', 0, '0.00'],
            ['90071992547409.91', Money::MAX_MINOR, '90071992547409.91'],
            ['-90071992547409.91', -Money::MAX_MINOR, '-90071992547409.91'],
        ];
    }

    #[DataProvider('invalidDecimals')]
    public function test_ambiguous_or_inexact_input_is_rejected(mixed $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimal($input);
    }

    public static function invalidDecimals(): array
    {
        return array_map(fn ($input) => [$input], [1.23, 123, null, true, '', ' 1.00', '1.00 ', '1e3', '+1', '01', '1,25', '.25', '1.', '1.001', "1.00\n"]);
    }

    public function test_arithmetic_and_half_up_rounding_do_not_use_float(): void
    {
        $unit = Money::fromDecimal('33.35')->discounted(1000);
        $this->assertSame('30.02', $unit->decimal());
        $this->assertSame('90.06', $unit->times(3)->decimal());
        $this->assertSame('-30.02', Money::fromDecimal('-33.35')->discounted(1000)->decimal());
        $this->assertSame('0.30', Money::fromDecimal('0.10')->plus(Money::fromDecimal('0.20'))->decimal());
        $this->assertSame('-0.10', Money::fromDecimal('0.10')->minus(Money::fromDecimal('0.20'))->decimal());
        $this->assertSame(0, $unit->times(0)->amountMinor);
        $this->assertSame(Money::MAX_MINOR, Money::fromMinor(Money::MAX_MINOR)->discounted(0)->amountMinor);
        $this->assertSame(0, Money::fromMinor(Money::MAX_MINOR)->discounted(10000)->amountMinor);
        $this->assertSame(4_503_599_627_370_496, Money::fromMinor(Money::MAX_MINOR)->discounted(5000)->amountMinor);
    }

    #[DataProvider('overflowCases')]
    public function test_overflow_is_rejected_before_precision_can_be_lost(string $operation): void
    {
        $this->expectException(OverflowException::class);
        match ($operation) {
            'decimal' => Money::fromDecimal('90071992547409.92'),
            'huge-decimal' => Money::fromDecimal(str_repeat('9', 100)),
            'min-int' => Money::fromMinor(PHP_INT_MIN),
            'plus' => Money::fromMinor(Money::MAX_MINOR)->plus(Money::fromMinor(1)),
            'minus' => Money::fromMinor(-Money::MAX_MINOR)->minus(Money::fromMinor(1)),
            'times' => Money::fromMinor(2)->times(PHP_INT_MAX),
        };
    }

    public static function overflowCases(): array
    {
        return array_map(fn ($operation) => [$operation], ['decimal', 'huge-decimal', 'min-int', 'plus', 'minus', 'times']);
    }

    #[DataProvider('invalidOperations')]
    public function test_invalid_arithmetic_arguments_are_rejected(string $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        match ($operation) {
            'minor-string' => Money::fromMinor('100'),
            'minor-float' => Money::fromMinor(100.0),
            'currency' => Money::fromMinor(100, 'USD'),
            'quantity-float' => Money::fromMinor(100)->times(1.5),
            'quantity-string' => Money::fromMinor(100)->times('2'),
            'quantity-negative' => Money::fromMinor(100)->times(-1),
            'discount-negative' => Money::fromMinor(100)->discounted(-1),
            'discount-too-large' => Money::fromMinor(100)->discounted(10001),
        };
    }

    public static function invalidOperations(): array
    {
        return array_map(fn ($operation) => [$operation], ['minor-string', 'minor-float', 'currency', 'quantity-float', 'quantity-string', 'quantity-negative', 'discount-negative', 'discount-too-large']);
    }
}
