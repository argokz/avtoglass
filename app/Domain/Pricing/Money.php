<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;
use JsonSerializable;
use OverflowException;

/** Exact KZT amounts. Bounds also preserve integer precision in JSON clients. */
final readonly class Money implements JsonSerializable
{
    public const MAX_MINOR = 9_007_199_254_740_991;

    private function __construct(public int $amountMinor, public string $currency = 'KZT')
    {
        if ($amountMinor < -self::MAX_MINOR || $amountMinor > self::MAX_MINOR) {
            throw new OverflowException('Money exceeds the supported integer range.');
        }
    }

    public static function fromMinor(mixed $amount, string $currency = 'KZT'): self
    {
        if (! is_int($amount) || $currency !== 'KZT') {
            throw new InvalidArgumentException('Money requires integer minor units in KZT.');
        }

        return new self($amount, $currency);
    }

    public static function fromDecimal(mixed $decimal): self
    {
        if (! is_string($decimal) || ! preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $decimal, $parts)) {
            throw new InvalidArgumentException('Expected an exact decimal string with at most two fractional digits.');
        }
        $digits = ltrim($parts[2].str_pad($parts[3] ?? '', 2, '0'), '0') ?: '0';
        $limit = (string) self::MAX_MINOR;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new OverflowException('Money exceeds the supported integer range.');
        }

        return new self(($parts[1] === '-' ? -1 : 1) * (int) $digits);
    }

    public function decimal(): string
    {
        $absolute = abs($this->amountMinor);

        return ($this->amountMinor < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function plus(self $other): self
    {
        return new self($this->amountMinor + $other->amountMinor);
    }

    public function minus(self $other): self
    {
        return new self($this->amountMinor - $other->amountMinor);
    }

    public function times(mixed $quantity): self
    {
        if (! is_int($quantity) || $quantity < 0) {
            throw new InvalidArgumentException('A multiplier must be a non-negative integer.');
        }
        if ($this->amountMinor !== 0 && $quantity > intdiv(self::MAX_MINOR, abs($this->amountMinor))) {
            throw new OverflowException('Money multiplication exceeds the supported range.');
        }

        return new self($this->amountMinor * $quantity);
    }

    /** Round a discount at the unit-price level, half up, before multiplying quantity. */
    public function discounted(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new InvalidArgumentException('Discount must be between 0 and 10000 basis points.');
        }
        $absolute = abs($this->amountMinor);
        $numerator = 10_000 - $basisPoints;
        // Split before multiplication so no intermediate float or integer overflow is possible.
        $rounded = intdiv($absolute, 10_000) * $numerator
            + intdiv(($absolute % 10_000) * $numerator + 5_000, 10_000);

        return new self($this->amountMinor < 0 ? -$rounded : $rounded);
    }

    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }
}
