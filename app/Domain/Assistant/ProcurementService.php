<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\Conversation;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Domain\Shared\PurchaseException;
use App\Models\Offer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** The requested lines survive independently of the bounded search cards. */
final class ProcurementService
{
    public function replace(BuyerContext $buyer, string $conversationId, array $input): array
    {
        $buyer = $buyer->fresh();
        Validator::make(['lines' => $input], ['lines' => ['required', 'array', 'min:1', 'max:1000']])->validate();
        $lines = [];
        foreach ($input as $row) {
            $reference = trim((string) ($row['article'] ?? $row['reference'] ?? ''));
            $quantity = $row['quantity'] ?? null;
            $validQuantity = (is_int($quantity) || is_string($quantity) && preg_match('/^\d{1,2}$/D', $quantity)) && (int) $quantity >= 1 && (int) $quantity <= ($buyer->wholesaleApproved ? 99 : 20);
            $lines[] = ['id' => (string) Str::uuid(), 'reference' => mb_substr($reference, 0, 128), 'quantity' => $validQuantity ? (int) $quantity : $quantity,
                'manufacturer' => mb_substr((string) ($row['manufacturer'] ?? ''), 0, 100), 'comment' => mb_substr((string) ($row['comment'] ?? ''), 0, 500),
                'issue' => $row['issue'] ?? (! $validQuantity || $reference === '' || mb_strlen($reference) > 128 ? 'invalid_row' : null), 'offer_id' => null, 'confirmed' => false, 'candidates' => []];
        }
        if (count($lines) > 500) {
            throw new PurchaseException('TOO_MANY_LINES', 422);
        }
        $codes = array_values(array_unique(array_map(fn ($line) => SearchOffers::normalizeCode($line['reference']), $lines)));
        $offers = Offer::query()->with(['product', 'supplier'])->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('is_published', true))->whereHas('supplier', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereIn(DB::raw("upper(regexp_replace(coalesce(article, ''), '[\\s.\\-]+', '', 'g'))"), $codes)
                ->orWhereHas('product', fn ($p) => $p->whereIn(DB::raw("upper(regexp_replace(coalesce(eurocode, ''), '[\\s.\\-]+', '', 'g'))"), $codes)
                    ->orWhereIn(DB::raw("upper(regexp_replace(coalesce(oem_code, ''), '[\\s.\\-]+', '', 'g'))"), $codes)))
            ->orderBy('id')->limit(5000)->get();
        $byCode = [];
        foreach ($offers as $offer) {
            foreach (array_unique([$offer->article, $offer->product->eurocode, $offer->product->oem_code]) as $code) {
                $byCode[SearchOffers::normalizeCode((string) $code)][] = ['offer_id' => $offer->id, 'article' => $offer->article,
                    'manufacturer' => $offer->supplier->name, 'name' => $offer->product->name];
            }
        }
        foreach ($lines as &$line) {
            $candidates = $byCode[SearchOffers::normalizeCode($line['reference'])] ?? [];
            $line['candidates'] = array_values(array_filter($candidates, fn ($c) => $line['manufacturer'] === '' || mb_strtolower($line['manufacturer']) === mb_strtolower($c['manufacturer'])));
            $line['status'] = $line['issue'] ? 'invalid' : (count($line['candidates']) === 1 ? 'matched' : (count($line['candidates']) > 1 ? 'ambiguous' : 'unresolved'));
        }
        unset($line);

        return DB::transaction(function () use ($buyer, $conversationId, $lines) {
            $chat = Conversation::query()->whereKey($conversationId)->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->firstOrFail();
            $session = app(ShoppingService::class)->get($chat);
            $state = $session->state;
            $state['lines'] = array_column($lines, null, 'id');
            $state['stage'] = 'procurement';
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();

            return $lines;
        });
    }

    public function choose(BuyerContext $buyer, string $conversationId, string $lineId, int $offerId): void
    {
        DB::transaction(function () use ($buyer, $conversationId, $lineId, $offerId) {
            $chat = Conversation::query()->whereKey($conversationId)->where('owner_key', ChatIdentity::ownerKey($buyer->fresh()))->lockForUpdate()->firstOrFail();
            if ($chat->active_turn_id) {
                throw new PurchaseException('CONVERSATION_BUSY', 409);
            }
            $session = app(ShoppingService::class)->get($chat);
            $state = $session->state;
            $line = $state['lines'][$lineId] ?? null;
            if (! $line || ($line['issue'] ?? null) || ! in_array($offerId, array_column($line['candidates'] ?? [], 'offer_id'), true)) {
                throw new PurchaseException('OFFER_SELECTION_REQUIRED', 422);
            }
            $state['lines'][$lineId]['offer_id'] = $offerId;
            $state['lines'][$lineId]['confirmed'] = true;
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
        });
    }

    public function items(ShoppingSession $session): array
    {
        $result = [];
        foreach ($session->state['lines'] ?? [] as $line) {
            if (! empty($line['issue'])) {
                throw new PurchaseException('INVALID_PROCUREMENT_LINE', 422);
            }
            $id = ! empty($line['confirmed']) ? ($line['offer_id'] ?? null) : null;
            $key = $id ? 'offer:'.$id : 'reference:'.$line['id'];
            $quantity = (int) $line['quantity'];
            if (isset($result[$key])) {
                $quantity += $result[$key]['quantity'];
            }
            if ($quantity > 99) {
                throw new PurchaseException('QUANTITY_LIMIT', 422);
            }
            $result[$key] = ['offer_id' => $id, 'reference' => $line['reference'], 'quantity' => $quantity, 'comment' => $line['comment'] ?? null,
                'selection_mode' => $id ? 'exact_article' : 'vehicle_fitment', 'article_confirmed' => $id !== null, 'manufacturer_confirmed' => $id !== null];
        }

        return array_values($result);
    }
}
