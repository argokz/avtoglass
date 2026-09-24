<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\PurchaseException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Runs before global input transforms; the API consumes bounded, typed JSON only. */
final class OrderingApiBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return $next($request);
        }
        if (! config('ordering.api_enabled')) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $persistentDrivers = ['file', 'database', 'redis', 'memcached', 'dynamodb'];
        if (app()->environment('testing')) {
            $persistentDrivers[] = 'array';
        }
        $lockStore = config('session.block_store') ?? config('cache.default');
        if (! in_array(config('session.driver'), $persistentDrivers, true)
            || ! in_array(config('cache.stores.'.$lockStore.'.driver'), $persistentDrivers, true)) {
            throw new PurchaseException('DEPENDENCY_UNAVAILABLE', 503);
        }
        if (! in_array($request->header('X-Locale', 'ru'), ['ru', 'kk'], true)) {
            throw ValidationException::withMessages(['locale' => 'UNSUPPORTED_LOCALE']);
        }
        if (! $request->isMethodSafe()) {
            if (! $request->isJson()) {
                throw new PurchaseException('UNSUPPORTED_MEDIA_TYPE', 415);
            }
            if (strlen($request->getContent()) > 1_048_576) {
                throw new PurchaseException('PAYLOAD_TOO_LARGE', 413);
            }
            try {
                $object = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR, depth: 64);
                if (! $object instanceof \stdClass) {
                    throw new JsonException('The root must be an object.');
                }
                $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new PurchaseException('INVALID_JSON', 400);
            }
            $request->attributes->set('ordering.payload', $payload);
        }

        return $next($request);
    }
}
