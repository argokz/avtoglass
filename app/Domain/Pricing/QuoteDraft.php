<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\DraftAccess;
use App\Domain\Pricing\Data\CheckoutQuoteView;
use App\Domain\Pricing\Models\CheckoutQuote;
use App\Domain\Shared\PurchaseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class QuoteDraft
{
    public function __construct(private readonly CalculateDraft $calculator) {}

    public function prepare(BuyerContext $buyer, string $draftId, int $expectedVersion): CheckoutQuoteView
    {
        return DB::transaction(function () use ($buyer, $draftId, $expectedVersion) {
            $buyer = $buyer->fresh();
            $draft = DraftAccess::query($buyer, $draftId)->lockForUpdate()->first();
            if (! $draft) {
                throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
            }
            $buyer->assertMode($draft->sales_mode);
            if ($draft->version !== $expectedVersion) {
                throw new PurchaseException('DRAFT_VERSION_CONFLICT', 409, ['current_version' => $draft->version]);
            }
            if ($draft->status !== 'active') {
                throw new PurchaseException('DRAFT_NOT_EDITABLE', 409);
            }
            $ttl = config('ordering.quote_ttl_minutes');
            if (! is_int($ttl) || $ttl <= 0 || $ttl > 1440) {
                throw new LogicException('Quote lifetime must be between 1 and 1440 minutes.');
            }
            $at = CarbonImmutable::now('UTC');
            $calculation = $this->calculator->calculate($draft, $buyer, $at);
            $version = (int) CheckoutQuote::query()->where('draft_id', $draft->id)->max('version') + 1;
            CheckoutQuote::query()->where('draft_id', $draft->id)->where('status', 'prepared')->update(['status' => 'superseded']);
            $quote = new CheckoutQuote;
            $quote->forceFill(['draft_id' => $draft->id, 'draft_version' => $draft->version, 'version' => $version,
                'snapshot' => $calculation->snapshot, 'source_hash' => $calculation->fingerprint(), 'created_by' => $buyer->actorId,
                'status' => 'prepared', 'expires_at' => $at->addMinutes($ttl)])->save();

            return CheckoutQuoteView::fromModel($quote, $draft->version);
        }, 3);
    }

    /** Read the original terms, without refreshing prices or creating a replacement quote. */
    public function get(BuyerContext $buyer, string $draftId, string $quoteId): CheckoutQuoteView
    {
        return DB::transaction(function () use ($buyer, $draftId, $quoteId) {
            $draft = DraftAccess::query($buyer->fresh(), $draftId)->sharedLock()->first();
            if (! $draft || ! Str::isUuid($quoteId)) {
                throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
            }
            $quote = CheckoutQuote::query()->whereKey($quoteId)->where('draft_id', $draft->id)->first();
            if (! $quote) {
                throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
            }

            return CheckoutQuoteView::fromModel($quote, $draft->version);
        }, 3);
    }

    /** Caller must still reserve and submit in the SAME outer transaction. This never confirms an order. */
    public function assertCurrent(BuyerContext $buyer, string $draftId, string $quoteId, int $expectedVersion): CheckoutQuoteView
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Quote revalidation requires the submission transaction.');
        }
        $buyer = $buyer->fresh();
        $draft = DraftAccess::query($buyer, $draftId)->lockForUpdate()->first();
        if (! $draft || ! Str::isUuid($quoteId)) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $quote = CheckoutQuote::query()->whereKey($quoteId)->where('draft_id', $draft->id)->first();
        if (! $quote) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $buyer->assertMode($draft->sales_mode);
        if ($draft->version !== $expectedVersion || $quote->draft_version !== $draft->version) {
            throw new PurchaseException('DRAFT_VERSION_CONFLICT', 409, ['current_version' => $draft->version]);
        }
        if ($draft->status !== 'active' || $quote->status !== 'prepared') {
            throw new PurchaseException('QUOTE_NOT_CURRENT', 409);
        }
        $at = CarbonImmutable::now('UTC');
        if ($quote->expires_at->lte($at)) {
            throw new PurchaseException('QUOTE_EXPIRED', 409);
        }
        $current = $this->calculator->calculate($draft, $buyer, $at);
        if (! hash_equals($quote->source_hash, $current->fingerprint())) {
            throw new PurchaseException('QUOTE_CHANGED', 409);
        }

        return CheckoutQuoteView::fromModel($quote, $draft->version);
    }
}
