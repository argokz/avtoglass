<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Commands\Idempotency;
use Illuminate\Console\Command;

final class PruneIdempotencyResults extends Command
{
    protected $signature = 'ordering:prune-idempotency';

    protected $description = 'Remove expired response payloads while retaining command keys and result references';

    public function handle(Idempotency $idempotency): int
    {
        if (! config('ordering.api_enabled')) {
            $this->info('Ordering API is disabled; no records changed.');

            return self::SUCCESS;
        }
        $this->info('Expired response payloads removed: '.$idempotency->pruneExpiredPayloads());

        return self::SUCCESS;
    }
}
