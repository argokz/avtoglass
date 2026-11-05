<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Data\OrderDraftView;
use Illuminate\Support\Facades\DB;

/** One active purchase shared by assistant, product selection and cart for each buyer/mode. */
final class ActiveDraft
{
    private function scope(BuyerContext $buyer, ?string $mode = null): string
    {
        return hash('sha256', ($buyer->customerId ? 'customer:'.$buyer->customerId : 'guest:'.$buyer->guestOwnerHash).':'.($mode ?? ($buyer->wholesaleApproved ? 'wholesale' : 'retail')));
    }

    public function current(BuyerContext $buyer, bool $create = false): ?OrderDraftView
    {
        $buyer = $buyer->fresh();

        return DB::transaction(function () use ($buyer, $create) {
            $scope = $this->scope($buyer);
            DB::table('purchase_workspaces')->insertOrIgnore(['owner_scope' => $scope]);
            $workspace = DB::table('purchase_workspaces')->where('owner_scope', $scope)->lockForUpdate()->first();
            if ($workspace->draft_id && DraftAccess::query($buyer, $workspace->draft_id)->where('status', 'active')->where('sales_mode', $buyer->wholesaleApproved ? 'wholesale' : 'retail')->exists()) {
                return app(DraftService::class)->get($buyer, $workspace->draft_id);
            }
            if (! $create) {
                return null;
            }
            $draft = app(DraftService::class)->create($buyer, $buyer->wholesaleApproved ? SalesMode::Wholesale : SalesMode::Retail);
            DB::table('purchase_workspaces')->where('owner_scope', $scope)->update(['draft_id' => $draft->id]);

            return $draft;
        });
    }

    public function bind(BuyerContext $buyer, string $draftId): void
    {
        $draft = app(DraftService::class)->get($buyer->fresh(), $draftId);
        DB::table('purchase_workspaces')->upsert([['owner_scope' => $this->scope($buyer, $draft->jsonSerialize()['sales_mode']), 'draft_id' => $draftId]], ['owner_scope'], ['draft_id']);
    }
}
