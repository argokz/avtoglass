<?php

declare(strict_types=1);

namespace App\Domain\Shared\Commands;

use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\PurchaseException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class Idempotency
{
    /**
     * All callbacks are database-only. A successful mutation and its response commit together.
     * Authorization deliberately runs on replays too, without checking the old expected version.
     */
    public function execute(BuyerContext $buyer, string $operation, string $key, array $input, Closure $authorize, Closure $command): CommandResult
    {
        if (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'IDEMPOTENCY_KEY_REQUIRED']);
        }
        if (! preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $operation)) {
            throw new LogicException('Command operation must be a server-defined identifier.');
        }
        try {
            $requestHash = hash_hmac('sha256', CanonicalJson::encode($input), (string) config('app.key'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['_input' => 'INVALID_VALUE_TYPE']);
        }
        $keyHash = hash('sha256', $key);
        $hours = config('ordering.idempotency_result_hours');
        if (! is_int($hours) || $hours < 1 || $hours > 720) {
            throw new LogicException('Idempotency result retention must be between 1 and 720 hours.');
        }

        return DB::transaction(function () use ($buyer, $operation, $keyHash, $requestHash, $hours, $authorize, $command) {
            $buyer = $buyer->fresh();
            $scope = self::scope($buyer);
            $at = CarbonImmutable::now('UTC');
            // PostgreSQL waits for an uncommitted competing insertion of the same unique key.
            // A rollback releases the key; a commit exposes the saved response to the waiter.
            $inserted = DB::table('idempotency_records')->insertOrIgnore([
                'owner_scope' => $scope, 'operation' => $operation, 'key_hash' => $keyHash, 'request_hash' => $requestHash,
                'expires_at' => $at->addHours($hours)->format('Y-m-d H:i:sP'),
                'created_at' => $at->format('Y-m-d H:i:sP'), 'updated_at' => $at->format('Y-m-d H:i:sP'),
            ]);
            $record = IdempotencyRecord::query()->where('owner_scope', $scope)->where('operation', $operation)->where('key_hash', $keyHash)->lockForUpdate()->firstOrFail();
            $buyer = $buyer->fresh(); // Waiting for the competing transaction may outlive access changes.
            if (! hash_equals($scope, self::scope($buyer))) {
                throw new PurchaseException('AUTH_REQUIRED', 401);
            }
            if (! hash_equals($record->request_hash, $requestHash)) {
                throw new PurchaseException('IDEMPOTENCY_CONFLICT', 409);
            }
            $authorize($buyer);
            if (! $inserted) {
                if ($record->expires_at->lte(CarbonImmutable::now('UTC')) || ($record->response_status !== null && $record->response_payload === null)) {
                    throw new PurchaseException('IDEMPOTENCY_RESULT_EXPIRED', 409, ['result_id' => $record->result_id]);
                }
                if ($record->response_status === null) {
                    throw new PurchaseException('IDEMPOTENCY_IN_PROGRESS', 409);
                }

                return new CommandResult($record->response_payload, $record->response_status, true);
            }
            $result = $command($buyer);
            if (! $result instanceof CommandResult) {
                throw new LogicException('An idempotent command must return CommandResult.');
            }
            // Refuse accidental models, floats or non-public objects in a saved response.
            CanonicalJson::encode($result->data);
            $record->forceFill(['response_payload' => $result->data, 'response_status' => $result->status,
                'result_id' => $result->data['id'] ?? null])->save();

            return $result;
        }, 3);
    }

    private static function scope(BuyerContext $buyer): string
    {
        return hash('sha256', $buyer->customerId !== null ? 'customer:'.$buyer->customerId : 'guest:'.$buyer->guestOwnerHash);
    }

    /** Retain the unique key and result reference permanently; expiration never permits re-execution. */
    public function pruneExpiredPayloads(): int
    {
        return IdempotencyRecord::query()->whereNotNull('response_payload')
            ->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:sP'))->update(['response_payload' => null]);
    }
}
