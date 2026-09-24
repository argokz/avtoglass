<?php

declare(strict_types=1);

namespace App\Http\Ordering;

use App\Domain\Shared\Commands\CommandResult;
use App\Domain\Shared\PurchaseException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiResponse
{
    public function locale(Request $request): string
    {
        $locale = $request->header('X-Locale', 'ru');

        return in_array($locale, ['ru', 'kk'], true) ? $locale : 'ru';
    }

    public function success(Request $request, array|CommandResult $result): JsonResponse
    {
        $data = $result instanceof CommandResult ? $result->data : $result;
        $status = $result instanceof CommandResult ? $result->status : 200;
        $meta = $this->meta($request);
        if ($result instanceof CommandResult) {
            $meta['replayed'] = $result->replayed;
        }

        return $this->response($request, ['data' => $data, 'meta' => $meta], $status);
    }

    /** @param  list<array<string,mixed>>  $items */
    public function page(Request $request, array $items, array $pageMeta): JsonResponse
    {
        return $this->response($request, [
            'data' => $items,
            'meta' => [...$this->meta($request), ...$pageMeta],
        ], 200);
    }

    public function error(Request $request, Throwable $exception): JsonResponse
    {
        $fields = [];
        $details = [];
        $headers = [];
        if ($exception instanceof PurchaseException) {
            $code = $exception->errorCode;
            $status = $exception->status;
            $details = array_intersect_key($exception->details, array_flip([
                'current_version', 'result_id', 'readiness', 'reasons', 'quote_id', 'draft_id',
                'offer_id', 'available_quantity',
            ]));
        } elseif ($exception instanceof ValidationException) {
            $code = 'VALIDATION_FAILED';
            $status = 422;
            foreach ($exception->errors() as $field => $messages) {
                $messageKey = match (true) {
                    $field === 'idempotency_key' => 'idempotency_key',
                    $field === 'expected_version' => 'expected_version',
                    in_array('DUPLICATE_OFFER', $messages, true) => 'duplicate_offer',
                    in_array('OFFER_UNAVAILABLE', $messages, true) => 'offer_unavailable',
                    str_ends_with($field, '.quantity') => 'quantity',
                    $field === 'locale' => 'locale',
                    default => 'invalid',
                };
                $fields[$field] = [__('ordering.validation.'.$messageKey, [], $this->locale($request))];
            }
        } elseif ($exception instanceof LockTimeoutException) {
            $status = 503;
            $code = 'SESSION_BUSY';
            $headers = ['Retry-After' => '1'];
        } elseif ($exception instanceof TokenMismatchException) {
            $status = 419;
            $code = 'CSRF_TOKEN_MISMATCH';
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                404 => 'RESOURCE_NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 413 => 'PAYLOAD_TOO_LARGE',
                415 => 'UNSUPPORTED_MEDIA_TYPE', 419 => 'CSRF_TOKEN_MISMATCH', 429 => 'RATE_LIMITED',
                default => 'INTERNAL_ERROR',
            };
            $headers = array_intersect_key($exception->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Allow']));
        } else {
            $status = 500;
            $code = 'INTERNAL_ERROR';
        }
        $locale = $this->locale($request);
        $key = 'ordering.errors.'.$code;
        $message = __($key, [], $locale);
        if ($message === $key) {
            $message = __('ordering.errors.INTERNAL_ERROR', [], $locale);
        }

        return $this->response($request, ['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields, ...$details], 'meta' => $this->meta($request)], $status, $headers);
    }

    private function meta(Request $request): array
    {
        if (! $request->attributes->has('ordering.request_id')) {
            $request->attributes->set('ordering.request_id', (string) Str::uuid());
        }

        return ['request_id' => $request->attributes->get('ordering.request_id')];
    }

    private function response(Request $request, array $body, int $status, array $headers = []): JsonResponse
    {
        return response()->json($body, $status, [...$headers, 'Cache-Control' => 'no-store, private',
            'Content-Language' => $this->locale($request), 'X-Content-Type-Options' => 'nosniff']);
    }
}
