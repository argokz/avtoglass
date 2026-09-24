<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validate even empty strings: optional form rules normally skip those values. */
final class InputType implements ValidationRule
{
    public bool $implicit = true;

    private function __construct(private string $type, private bool $nullable = false) {}

    public static function array(): self
    {
        return new self('array');
    }

    public static function integer(bool $nullable = false): self
    {
        return new self('int', $nullable);
    }

    public static function boolean(bool $nullable = false): self
    {
        return new self('bool', $nullable);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null && $this->nullable) {
            return;
        }
        if (get_debug_type($value) !== $this->type) {
            $fail('INVALID_VALUE_TYPE');
        }
    }
}
