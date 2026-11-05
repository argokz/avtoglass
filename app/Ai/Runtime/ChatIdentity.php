<?php

namespace App\Ai\Runtime;

use App\Domain\Identity\BuyerContext;

/** Request-only adapter: queued work never reads auth or session. */
final class ChatIdentity
{
    public static function buyer(): BuyerContext
    {
        $locale = in_array(app()->getLocale(), ['ru', 'kk'], true) ? app()->getLocale() : 'ru';
        if (auth()->check()) {
            return BuyerContext::account((int) auth()->id(), $locale);
        }

        return BuyerContext::guest(self::guestToken(), $locale);
    }

    public static function guestToken(): string
    {
        $token = session('ordering.guest_token');
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $token = bin2hex(random_bytes(32));
            session(['ordering.guest_token' => $token]);
        }

        return $token;
    }

    public static function ownerKey(BuyerContext $buyer): string
    {
        return $buyer->actorId !== null ? 'actor:'.$buyer->actorId : 'guest:'.$buyer->guestOwnerHash;
    }
}
