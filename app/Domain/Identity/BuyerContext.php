<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Ordering\SalesMode;
use App\Domain\Shared\PurchaseException;
use App\Models\User;
use InvalidArgumentException;

/** Server-created identity, never constructed from customer/phone fields in a checkout payload. */
final readonly class BuyerContext
{
    private function __construct(
        public ?int $actorId,
        public ?int $customerId,
        public ?string $guestOwnerHash,
        public string $locale,
        public bool $wholesaleApproved,
        public int $discountBasisPoints,
    ) {
        if (! in_array($locale, ['ru', 'kk'], true)) {
            throw new InvalidArgumentException('Unsupported buyer locale.');
        }
    }

    /** Token comes from a server-managed guest session, not an arbitrary request field. */
    public static function guest(string $token, string $locale = 'ru'): self
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new InvalidArgumentException('A guest owner requires a 256-bit hexadecimal token.');
        }

        return new self(null, null, hash('sha256', $token), $locale, false, 0);
    }

    /** actorId must be obtained by the authenticated adapter, never by phone lookup. */
    public static function account(int $actorId, string $locale = 'ru'): self
    {
        $user = User::query()->with('customer')->find($actorId);
        if (! $user || ! $user->is_active || $user->isStaff() || ! $user->customer) {
            throw new PurchaseException('AUTH_REQUIRED', 401);
        }
        $approved = $user->customer->seesWholesalePrices();

        return new self($user->id, $user->customer_id, null, $locale, $approved,
            $approved ? max(0, min(40, (int) $user->customer->wholesale_discount_percent)) * 100 : 0);
    }

    public function fresh(): self
    {
        return $this->actorId === null ? $this : self::account($this->actorId, $this->locale);
    }

    public function assertMode(SalesMode $mode): void
    {
        if ($mode === SalesMode::Wholesale && ! $this->wholesaleApproved) {
            throw new PurchaseException('WHOLESALE_REQUIRED', 403);
        }
    }
}
