<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Data\OrderDraftView;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\Models\OrderDraftItem;
use App\Domain\Shared\PurchaseException;
use App\Domain\Shared\Validation\InputType;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Application operations CreateDraft / ReadDraft / UpdateDraft. No session or checkout side effects. */
final class DraftService
{
    public function create(BuyerContext $buyer, SalesMode $mode = SalesMode::Retail): OrderDraftView
    {
        return DB::transaction(function () use ($buyer, $mode) {
            $buyer = $buyer->fresh();
            $buyer->assertMode($mode);
            $draft = new OrderDraft;
            $draft->forceFill([
                'customer_id' => $buyer->customerId, 'guest_owner_hash' => $buyer->guestOwnerHash,
                'sales_mode' => $mode, 'version' => 1, 'status' => 'active', 'locale' => $buyer->locale,
                'vehicle' => [], 'contacts' => [], 'fulfillment' => ['type' => 'pickup'],
            ])->save();

            return OrderDraftView::fromModel($draft->load('items'));
        }, 3);
    }

    public function get(BuyerContext $buyer, string $id): OrderDraftView
    {
        return DB::transaction(function () use ($buyer, $id) {
            // Keep the header version and the separate items query in one consistent read.
            $draft = DraftAccess::query($buyer->fresh(), $id)->sharedLock()->first();
            if (! $draft) {
                throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
            }

            return OrderDraftView::fromModel($draft->load('items'));
        }, 3);
    }

    /** Metadata is patched; when supplied, items is the complete replacement list. */
    public function update(BuyerContext $buyer, string $id, int $expectedVersion, array $changes): OrderDraftView
    {
        return DB::transaction(function () use ($buyer, $id, $expectedVersion, $changes) {
            $buyer = $buyer->fresh();
            $draft = DraftAccess::query($buyer, $id)->lockForUpdate()->first();
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
            if ($draft->version >= 2_147_483_647) {
                throw new PurchaseException('DRAFT_VERSION_LIMIT', 409);
            }
            $changes = $this->validateChanges($changes, $draft->sales_mode);
            if (isset($changes['vehicle'])) {
                $previousVehicle = $draft->vehicle;
                $modelChanged = array_key_exists('model_id', $changes['vehicle']) && ($changes['vehicle']['model_id'] ?? null) !== ($previousVehicle['model_id'] ?? null);
                $yearChanged = array_key_exists('year', $changes['vehicle']) && ($changes['vehicle']['year'] ?? null) !== ($previousVehicle['year'] ?? null);
                if ($modelChanged || $yearChanged) {
                    $draft->vehicle = array_diff_key($previousVehicle, array_flip($modelChanged ? ['year', 'brand_id', 'generation_id', 'body', 'doors', 'options'] : ['generation_id', 'body', 'doors', 'options']));
                }
            }
            if (array_key_exists('items', $changes)) {
                $this->replaceItems($draft, $changes['items']);
            }
            foreach (['vehicle', 'contacts', 'fulfillment'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $draft->{$field} = array_replace_recursive($draft->{$field}, $changes[$field]);
                }
            }
            if (array_key_exists('payment_method', $changes)) {
                if (! array_key_exists($changes['payment_method'], PaymentOptions::available($buyer))) {
                    throw ValidationException::withMessages(['payment_method' => 'PAYMENT_METHOD_UNAVAILABLE']);
                }
                $draft->payment_method = $changes['payment_method'];
            }
            $fulfillment = $draft->fulfillment;
            if (! in_array($fulfillment['type'] ?? '', ['delivery', 'visit'], true)) {
                unset($fulfillment['address']);
            }
            if (! in_array($fulfillment['type'] ?? '', ['installation', 'visit'], true)) {
                unset($fulfillment['slot_start'], $fulfillment['slot_end']);
            }
            $draft->fulfillment = $fulfillment;
            if (isset($changes['locale'])) {
                $draft->locale = $changes['locale'];
            }
            $draft->version++;
            $draft->save();

            // Return exactly what is stored: Eloquent's SQL builder may update the
            // timestamp again when two saves share a second. The draft lock is held.
            return OrderDraftView::fromModel($draft->refresh()->load('items'));
        }, 3);
    }

    private function validateChanges(array $changes, SalesMode $mode): array
    {
        if ($changes === [] || array_diff(array_keys($changes), ['items', 'vehicle', 'contacts', 'fulfillment', 'locale', 'payment_method'])) {
            throw ValidationException::withMessages(['_input' => 'DRAFT_FIELDS_INVALID']);
        }
        $maxQuantity = config('ordering.limits.'.$mode->value.'_max_quantity');
        $maxItems = config('ordering.limits.max_draft_items');
        if (! is_int($maxQuantity) || $maxQuantity < 1 || $maxQuantity > 99 || ! is_int($maxItems) || $maxItems < 1 || $maxItems > 500) {
            throw new LogicException('Ordering limits must fit the database bounds: quantity 1–99 and items 1–500.');
        }

        return Validator::make($changes, [
            'payment_method' => ['sometimes', 'required', 'string', Rule::in(['cash', 'card', 'kaspi', 'invoice'])],
            'items' => ['sometimes', InputType::array(), 'array', 'list', 'max:'.$maxItems],
            'items.*' => [InputType::array(), 'array:line_id,offer_id,reference,quantity,comment,selection_mode,article_confirmed,manufacturer_confirmed'],
            'items.*.line_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'items.*.offer_id' => ['nullable', InputType::integer(nullable: true), 'integer', 'min:1'],
            'items.*.reference' => ['nullable', 'string', 'max:128'],
            'items.*.quantity' => ['required', InputType::integer(), 'integer', 'between:1,'.$maxQuantity],
            'items.*.comment' => ['nullable', 'string', 'max:500'],
            'items.*.selection_mode' => ['sometimes', 'required', Rule::in(['vehicle_fitment', 'exact_article'])],
            'items.*.article_confirmed' => ['sometimes', InputType::boolean()],
            'items.*.manufacturer_confirmed' => ['sometimes', InputType::boolean()],
            'contacts' => ['sometimes', InputType::array(), 'array:name,phone'],
            'contacts.name' => ['nullable', 'string', 'max:100'],
            'contacts.phone' => ['nullable', 'string', 'max:32'],
            'vehicle' => ['sometimes', InputType::array(), 'array:brand_id,model_id,generation_id,year,body,doors,vin,options'],
            'vehicle.brand_id' => ['nullable', InputType::integer(nullable: true), 'integer', 'min:1'],
            'vehicle.model_id' => ['nullable', InputType::integer(nullable: true), 'integer', 'min:1'],
            'vehicle.generation_id' => ['nullable', InputType::integer(nullable: true), 'integer', 'min:1'],
            'vehicle.year' => ['nullable', InputType::integer(nullable: true), 'integer', 'between:1900,'.(now()->year + 2)],
            'vehicle.body' => ['nullable', 'string', 'max:80'],
            'vehicle.doors' => ['nullable', 'string', 'max:8'],
            'vehicle.vin' => ['nullable', 'string', 'max:17'],
            'vehicle.options' => ['sometimes', InputType::array(), 'array:'.implode(',', array_keys(Product::OPTION_FLAGS))],
            'vehicle.options.*' => ['nullable', InputType::boolean(nullable: true)],
            'fulfillment' => ['sometimes', InputType::array(), 'array:type,city,address,service_point_id,slot_start,slot_end'],
            'fulfillment.type' => ['sometimes', 'required', Rule::in(['pickup', 'delivery', 'installation', 'visit'])],
            'fulfillment.city' => ['nullable', 'string', 'max:120'],
            'fulfillment.address' => ['nullable', 'string', 'max:500'],
            'fulfillment.service_point_id' => ['nullable', InputType::integer(nullable: true), 'integer', 'min:1'],
            'fulfillment.slot_start' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'fulfillment.slot_end' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
            'locale' => ['sometimes', 'required', Rule::in(['ru', 'kk'])],
        ])->validate();
    }

    private function replaceItems(OrderDraft $draft, array $items): void
    {
        $existing = $draft->items()->get()->keyBy('line_id');
        $ids = array_filter(array_column($items, 'offer_id'), fn ($id) => $id !== null);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'DUPLICATE_OFFER']);
        }
        // A single bounded catalogue query; do not validate 500 rows with 500 exists queries.
        $offers = Offer::query()->with(['product', 'supplier'])->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
        $rows = [];
        $timestamp = now()->utc()->format('Y-m-d H:i:sP');
        foreach ($items as $position => $item) {
            $lineId = strtolower($item['line_id']);
            $old = $existing->get($lineId);
            $offerId = $item['offer_id'] ?? null;
            $offer = $offerId === null ? null : $offers->get($offerId);
            if ($offerId !== null && (! $offer || ((! $offer->is_active || ! $offer->supplier->is_active || ! $offer->product->is_published) && $old?->offer_id !== $offerId))) {
                throw ValidationException::withMessages(["items.$position.offer_id" => 'OFFER_UNAVAILABLE']);
            }
            $reference = trim($item['reference'] ?? '') ?: ($offer?->article ?: ($old?->reference ?? ''));
            if ($reference === '' && $offer) {
                $reference = $offer->product->eurocode ?: 'offer:'.$offer->id;
            }
            if ($reference === '') {
                throw ValidationException::withMessages(["items.$position.reference" => 'REFERENCE_REQUIRED']);
            }
            $keepSnapshot = $old && $old->reference === $reference && $old->offer_id === null;
            $rows[] = [
                'draft_id' => $draft->id, 'line_id' => $lineId, 'position' => $position, 'offer_id' => $offerId,
                'reference' => $reference, 'article_snapshot' => $offer?->article ?? ($keepSnapshot ? $old->article_snapshot : null),
                'name' => $offer?->product->name ?? ($keepSnapshot ? $old->name : $reference),
                'manufacturer' => $offer?->supplier->name ?? ($keepSnapshot ? $old->manufacturer : null),
                'quantity' => $item['quantity'], 'selection_mode' => $item['selection_mode'] ?? 'vehicle_fitment',
                'article_confirmed' => $item['article_confirmed'] ?? false, 'manufacturer_confirmed' => $item['manufacturer_confirmed'] ?? false,
                'comment' => $item['comment'] ?? null, 'created_at' => $old?->created_at?->utc()->format('Y-m-d H:i:sP') ?? $timestamp, 'updated_at' => $timestamp,
            ];
        }
        $draft->items()->delete();
        if ($rows) {
            OrderDraftItem::query()->insert($rows);
        }
    }
}
