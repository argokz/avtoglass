<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use RuntimeException;

/** Application failure; HTTP/Livewire adapters supply the localized user-facing message. */
final class PurchaseException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $details = [])
    {
        parent::__construct($errorCode);
    }
}
