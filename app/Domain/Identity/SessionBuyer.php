<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Models\User;

/** Resolve buyer identity for storefront, wholesale and assistant adapters. */
final class SessionBuyer
{
    public static function current(?string $locale = null): BuyerContext
    {
        $locale = in_array($locale, ['ru', 'kk'], true) ? $locale : (app()->getLocale() === 'kk' ? 'kk' : 'ru');
        $user = auth()->user();
        if ($user instanceof User && ! $user->isStaff() && $user->customer_id !== null) {
            return BuyerContext::account((int) $user->getAuthIdentifier(), $locale);
        }

        $token = session('ordering.guest_token');
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            // Search-only adapters may create a guest token so later draft ownership matches.
            session()->put('ordering.guest_token', $token);
        }

        return BuyerContext::guest($token, $locale);
    }
}
