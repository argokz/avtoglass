<?php

declare(strict_types=1);

namespace App\Domain\Shared\Commands;

use InvalidArgumentException;

final readonly class CommandResult
{
    public function __construct(public array $data, public int $status = 200, public bool $replayed = false)
    {
        if ($status < 200 || $status > 299) {
            throw new InvalidArgumentException('Only successful command results can be persisted.');
        }
    }
}
