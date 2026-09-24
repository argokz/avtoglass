<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

enum SalesMode: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
}
