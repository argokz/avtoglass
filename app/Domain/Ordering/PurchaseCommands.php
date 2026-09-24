<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Pricing\QuoteDraft;
use App\Domain\Shared\Commands\CommandResult;
use App\Domain\Shared\Commands\Idempotency;
use App\Domain\Shared\PurchaseException;

/** Shared write entry point for HTTP, Livewire and assistant adapters. */
final class PurchaseCommands
{
    public function __construct(
        private readonly Idempotency $idempotency,
        private readonly DraftService $drafts,
        private readonly QuoteDraft $quotes,
        private readonly SubmitDraft $submit,
    ) {}

    public function createDraft(BuyerContext $buyer, string $key, SalesMode $mode = SalesMode::Retail): CommandResult
    {
        return $this->idempotency->execute($buyer, 'draft.create.v1', $key, ['sales_mode' => $mode->value, 'locale' => $buyer->locale],
            fn (BuyerContext $current) => $current->assertMode($mode),
            fn (BuyerContext $current) => new CommandResult($this->drafts->create($current, $mode)->jsonSerialize(), 201));
    }

    public function updateDraft(BuyerContext $buyer, string $draftId, string $key, int $expectedVersion, array $changes): CommandResult
    {
        $draftId = strtolower($draftId);

        return $this->idempotency->execute($buyer, 'draft.update.v1', $key, ['draft_id' => $draftId, 'expected_version' => $expectedVersion, 'changes' => $changes],
            fn (BuyerContext $current) => $this->authorizeDraft($current, $draftId),
            fn (BuyerContext $current) => new CommandResult($this->drafts->update($current, $draftId, $expectedVersion, $changes)->jsonSerialize()));
    }

    public function prepareQuote(BuyerContext $buyer, string $draftId, string $key, int $expectedVersion): CommandResult
    {
        $draftId = strtolower($draftId);

        return $this->idempotency->execute($buyer, 'draft.quote.v1', $key, ['draft_id' => $draftId, 'expected_version' => $expectedVersion],
            fn (BuyerContext $current) => $this->authorizeDraft($current, $draftId),
            fn (BuyerContext $current) => new CommandResult($this->quotes->prepare($current, $draftId, $expectedVersion)->jsonSerialize(), 201));
    }

    public function submitDraft(BuyerContext $buyer, string $draftId, string $key, string $quoteId, int $expectedVersion, string $action): CommandResult
    {
        $draftId = strtolower($draftId);
        $quoteId = strtolower($quoteId);

        return $this->idempotency->execute(
            $buyer,
            'draft.submit.v1',
            $key,
            ['draft_id' => $draftId, 'quote_id' => $quoteId, 'expected_version' => $expectedVersion, 'action' => $action],
            fn (BuyerContext $current) => $this->authorizeDraft($current, $draftId),
            fn (BuyerContext $current) => new CommandResult(
                $this->submit->handle($current, $draftId, $quoteId, $expectedVersion, $action),
                201,
            ),
        );
    }

    private function authorizeDraft(BuyerContext $buyer, string $id): void
    {
        // Lock ordering: idempotency record, then draft, then the command's catalogue/resources.
        $draft = DraftAccess::query($buyer, $id)->lockForUpdate()->first();
        if (! $draft) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $buyer->assertMode($draft->sales_mode);
    }
}
