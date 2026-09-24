<?php

namespace App\Wholesale\Import;

use RuntimeException;

final class PurchaseImportException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
