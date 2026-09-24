<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use App\Http\Ordering\ApiResponse;
use Closure;
use Illuminate\Http\Request;

final class OrderingBuyer
{
    public function handle(Request $request, Closure $next)
    {
        $locale = app(ApiResponse::class)->locale($request);
        if ($request->user()) {
            $buyer = BuyerContext::account((int) $request->user()->getAuthIdentifier(), $locale);
        } else {
            $token = $request->session()->get('ordering.guest_token');
            if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
                // Persist the owner in a short, locked bootstrap request before any
                // business mutation. A slow first command must not invent its owner.
                if (! $request->routeIs('ordering.session')) {
                    throw new PurchaseException('SESSION_REQUIRED', 409);
                }
                $token = bin2hex(random_bytes(32));
                $request->session()->put('ordering.guest_token', $token);
            }
            $buyer = BuyerContext::guest($token, $locale);
        }
        $request->attributes->set('ordering.buyer', $buyer);

        return $next($request);
    }
}
