<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Shared\PurchaseException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class DraftAccess
{
    /** Caller controls the transaction and lock mode; owner filtering is always applied first. */
    public static function query(BuyerContext $buyer, string $id): Builder
    {
        if (! Str::isUuid($id)) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $query = OrderDraft::query()->whereKey($id);

        return $buyer->customerId !== null
            ? $query->where('customer_id', $buyer->customerId)->whereNull('guest_owner_hash')
            : $query->where('guest_owner_hash', $buyer->guestOwnerHash)->whereNull('customer_id');
    }
}
