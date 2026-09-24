<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Outbox\OutboxEvent;
use App\Jobs\ProcessOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ProcessOutbox extends Command
{
    protected $signature = 'ordering:process-outbox {--limit=100}';

    protected $description = 'Dispatch pending outbox events after a successful commit';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = OutboxEvent::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('available_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'))
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessOutboxEvent::dispatch((string) $id);
        }
        $this->info('Outbox events queued: '.$ids->count());

        return self::SUCCESS;
    }
}
