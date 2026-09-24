<?php

declare(strict_types=1);

namespace App\Domain\Shared\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Writes durable post-commit work inside the caller's transaction. */
final class Outbox
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(string $eventType, string $aggregateType, string $aggregateId, array $payload, ?string $eventId = null): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox events must be recorded inside the business transaction.');
        }
        $at = CarbonImmutable::now('UTC');
        $event = new OutboxEvent;
        $event->forceFill([
            'id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'event_id' => $eventId ?? (string) Str::uuid(),
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $at,
            'created_at' => $at,
        ])->save();

        return $event;
    }
}
