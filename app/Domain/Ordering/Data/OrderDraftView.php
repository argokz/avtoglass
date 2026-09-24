<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Data;

use App\Domain\Ordering\Models\OrderDraft;
use JsonSerializable;

/** Public allowlist: no owner token, account identifier, cost price or raw Eloquent serialization. */
final readonly class OrderDraftView implements JsonSerializable
{
    private function __construct(public string $id, public int $version, private array $data) {}

    public static function fromModel(OrderDraft $draft): self
    {
        $items = $draft->items->map(fn ($item) => [
            'line_id' => $item->line_id, 'offer_id' => $item->offer_id, 'reference' => $item->reference, 'article' => $item->article_snapshot,
            'name' => $item->name, 'manufacturer' => $item->manufacturer, 'quantity' => $item->quantity,
            'selection_mode' => $item->selection_mode, 'article_confirmed' => $item->article_confirmed,
            'manufacturer_confirmed' => $item->manufacturer_confirmed, 'comment' => $item->comment,
            'validation_issues' => $item->offer_id === null ? ['UNRESOLVED_ITEM'] : [],
        ])->values()->all();

        return new self($draft->id, $draft->version, [
            'sales_mode' => $draft->sales_mode->value, 'status' => $draft->status, 'locale' => $draft->locale,
            'vehicle' => $draft->vehicle, 'contacts' => $draft->contacts, 'fulfillment' => $draft->fulfillment,
            'items' => $items, 'updated_at' => $draft->updated_at->utc()->toIso8601String(),
        ]);
    }

    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'version' => $this->version, ...$this->data];
    }
}
