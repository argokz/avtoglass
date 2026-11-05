<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\AssistantOrderIntent;
use App\Ai\AssistantSearch;
use App\Ai\Models\AiCheckout;
use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\Conversation;
use App\Domain\Catalog\OfferVehicleConstraints;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\DraftAccess;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\SalesMode;
use App\Domain\Shared\PurchaseException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Durable shopping facts, independent of model context length. Lock conversation before session. */
final class ShoppingService
{
    public function get(Conversation $chat): ShoppingSession
    {
        return ShoppingSession::query()->firstOrCreate(['conversation_id' => $chat->id], ['state' => ['intent' => [], 'lines' => [], 'selected' => [], 'stage' => 'vehicle']]);
    }

    public function accept(Conversation $chat, string $text): void
    {
        $session = $this->get($chat);
        $state = $session->state;
        $intent = AssistantOrderIntent::fromMessages([['role' => 'user', 'text' => $text]], settings()->cities());
        if (! empty($state['awaiting_option']) && preg_match('/^(да|есть|нет|не знаю|иә|бар|жоқ|білмеймін)[.!]?$/ui', trim($text), $answer)) {
            $value = mb_strtolower($answer[1]);
            $intent['options'][$state['awaiting_option']] = match ($value) {
                'да', 'есть', 'иә', 'бар' => true, 'нет', 'жоқ' => false, default => null
            };
            $state['awaiting_option'] = null;
            if (in_array($value, ['не знаю', 'білмеймін'], true)) {
                $state['stage'] = 'manager_review';
            }
        }
        $params = AssistantSearch::parameters(['query' => $text]);
        $query = AssistantSearch::focusTerm($text);
        $model = $query === '' || isset($intent['offer_id']) ? null : app(SearchOffers::class)->resolveModel([], $query);
        if ($model === null && ! isset($intent['offer_id']) && AssistantSearch::references($text) === []) {
            $models = collect(AssistantSearch::modelTerms($text))
                ->map(fn ($term) => app(SearchOffers::class)->resolveModel([], $term))->filter()->unique('id');
            if ($models->count() === 1) {
                $model = $models->first();
            }
        }
        $changingVehicle = $model !== null && ($state['intent']['car_model_id'] ?? null) !== $model->id;
        $unknownVehicle = $model === null && ! isset($intent['offer_id']) && AssistantSearch::references($text) === []
            && (isset($intent['year']) || isset($params['glass_type'])) && $query !== ''
            && isset($state['intent']['query']) && mb_strtolower($query) !== mb_strtolower($state['intent']['query']);
        $changingVehicle = $changingVehicle || $unknownVehicle;
        $yearChanged = isset($intent['year']) && isset($state['intent']['year']) && $intent['year'] !== $state['intent']['year'];
        if ($changingVehicle || $yearChanged) {
            $resetFields = $changingVehicle ? ['car_model_id', 'brand_id', 'year', 'query', 'generation_id', 'body', 'doors', 'options', 'offer_id'] : ['generation_id', 'options', 'offer_id'];
            foreach ($resetFields as $field) {
                unset($state['intent'][$field]);
            }
            $state['selected'] = [];
            $state['search'] = [];
            $state['awaiting_option'] = null;
        }
        if ($model !== null) {
            $intent['car_model_id'] = $model->id;
            $intent['brand_id'] = $model->car_brand_id;
            $intent['query'] = $model->name;
        }
        if ($unknownVehicle) {
            $intent['query'] = $query;
        }
        if (isset($params['glass_type'])) {
            $intent['glass_type'] = $params['glass_type'];
        }
        $state['intent'] = array_replace_recursive($state['intent'] ?? [], $intent);
        if (($intent['fulfillment'] ?? null) === 'pickup') {
            unset($state['intent']['address']);
        }
        if (! empty($state['intent']['without_installation'])) {
            unset($state['intent']['slot_start'], $state['intent']['slot_end']);
        }
        if (preg_match('/для\s+друга|чуж\w*\s+машин|досым|басқа\s+көлік/ui', $text)) {
            $state['temporary'] = true;
        }
        $refs = AssistantSearch::references($text);
        if (count($refs) > 1) {
            $quantities = AssistantSearch::lineQuantities($text);
            $state['lines'] = [];
            foreach ($refs as $ref) {
                $key = SearchOffers::normalizeCode($ref);
                $state['lines'][$key] = ['reference' => $ref, 'quantity' => $quantities[mb_strtoupper($ref)] ?? 1, 'offer_id' => null, 'status' => 'unresolved'];
            }
        }
        if (isset($intent['offer_id'])) {
            $allowed = array_column($state['search']['items'] ?? [], 'offer_id');
            if (in_array($intent['offer_id'], $allowed, true)) {
                $state['selected'] = [$intent['offer_id']];
            }
        }
        $state['stage'] = in_array(null, $state['intent']['options'] ?? [], true) ? 'manager_review'
            : (($state['selected'] ?? []) !== [] ? 'fulfillment' : (($state['intent']['query'] ?? '') !== '' ? 'selection' : 'vehicle'));
        $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
        if ($session->draft_id && ($chat->actor_id !== null || $chat->guest_token !== null)) {
            $buyer = $chat->buyer();
            $draft = DraftAccess::query($buyer, $session->draft_id)->where('status', 'active')->lockForUpdate()->first();
            if ($draft) {
                $patch = [];
                if ($changingVehicle || $yearChanged || array_key_exists('options', $intent)) {
                    $vehicle = array_intersect_key($state['intent'], array_flip(['year', 'generation_id', 'body', 'doors', 'options']));
                    $patch['vehicle'] = ['model_id' => $state['intent']['car_model_id'] ?? null, ...$vehicle];
                    if (($changingVehicle || $yearChanged) && $draft->sales_mode === SalesMode::Retail) {
                        $patch['items'] = [];
                    }
                }
                if (array_intersect(['fulfillment', 'city', 'address'], array_keys($intent))) {
                    $patch['fulfillment'] = array_intersect_key($intent, array_flip(['city', 'address']));
                    if (isset($intent['fulfillment'])) {
                        $patch['fulfillment']['type'] = $intent['fulfillment'];
                    }
                }
                if ($patch !== []) {
                    app(DraftService::class)->update($buyer, $draft->id, $draft->version, $patch);
                }
            }
        }
        if ($chat->actor_id !== null) {
            app(MemoryService::class)->remember($chat->buyer(), $state['intent'], (bool) ($state['temporary'] ?? false));
        }
    }

    public function claimGuest(BuyerContext $buyer, string $guestToken, string $conversationId): void
    {
        $buyer = $buyer->fresh();
        if ($buyer->customerId === null) {
            throw new PurchaseException('AUTH_REQUIRED', 401);
        }
        $guest = BuyerContext::guest($guestToken, $buyer->locale);
        DB::transaction(function () use ($buyer, $guest, $conversationId) {
            // Serialize imports into this account to respect the conversation limit.
            DB::table('customers')->where('id', $buyer->customerId)->lockForUpdate()->first();
            $chat = Conversation::query()->whereKey($conversationId)->where('owner_key', ChatIdentity::ownerKey($guest))->lockForUpdate()->firstOrFail();
            if ($chat->active_turn_id || Conversation::query()->where('owner_key', ChatIdentity::ownerKey($buyer))->count() >= 50) {
                throw new PurchaseException('CONVERSATION_BUSY', 409);
            }
            $operations = DB::table('ai_turns')->where('conversation_id', $chat->id)->pluck('operation_id');
            $checkouts = AiCheckout::query()->whereIn('turn_id', $operations)->where('owner_scope', hash('sha256', 'guest:'.$guest->guestOwnerHash))->lockForUpdate()->get();
            foreach ($checkouts as $checkout) {
                if ($checkout->status === 'submitted' && ! empty($checkout->result['order_id'])) {
                    $order = Order::query()->whereKey($checkout->result['order_id'])->where('purchase_guest_owner_hash', $guest->guestOwnerHash)->first();
                    if ($order) {
                        DB::table('purchase_access_grants')->insertOrIgnore(['order_id' => $order->id, 'customer_id' => $buyer->customerId, 'created_at' => now()]);
                    }
                }
                $draftId = $checkout->snapshot['draft_id'] ?? null;
                if ($draftId) {
                    DB::table('order_drafts')->where('id', $draftId)->where('guest_owner_hash', $guest->guestOwnerHash)
                        ->update(['customer_id' => $buyer->customerId, 'guest_owner_hash' => null]);
                }
                $checkout->forceFill(['owner_scope' => hash('sha256', 'customer:'.$buyer->customerId)])->save();
            }
            $session = ShoppingSession::query()->whereKey($chat->id)->first();
            if ($session?->draft_id) {
                DB::table('order_drafts')->where('id', $session->draft_id)->where('guest_owner_hash', $guest->guestOwnerHash)
                    ->update(['customer_id' => $buyer->customerId, 'guest_owner_hash' => null]);
            }
            $chat->forceFill(['owner_key' => ChatIdentity::ownerKey($buyer), 'actor_id' => $buyer->actorId, 'guest_token' => null])->save();
        });
        $session = ShoppingSession::query()->find($conversationId);
        if ($session?->draft_id) {
            app(ActiveDraft::class)->bind($buyer, $session->draft_id);
        }
    }

    public function searched(Conversation $chat, array $args, array $result): void
    {
        $session = $this->get($chat);
        $state = $session->state;
        $state['search'] = ['args' => $args, 'items' => $result['items'] ?? [], 'at' => now()->toIso8601String()];
        $state['selected'] = [];
        unset($state['intent']['offer_id']);
        $state['intent'] = array_replace($state['intent'] ?? [], array_intersect_key($result['vehicle'] ?? [], array_flip(['car_model_id', 'brand_id', 'year', 'generation_id'])));
        $missing = array_values(array_unique(array_merge([], ...array_map(fn ($item) => $item['missing_options'] ?? $item['fitment']['missing_options'] ?? [], $result['items'] ?? []))));
        $missing = array_values(array_filter($missing, fn ($flag) => ! array_key_exists($flag, $state['intent']['options'] ?? [])));
        $state['awaiting_option'] = $missing[0] ?? null;
        $state['stage'] = in_array(null, $state['intent']['options'] ?? [], true) ? 'manager_review' : ($missing === [] ? 'selection' : 'options');
        $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
    }

    public function context(Conversation $chat, BuyerContext $buyer): array
    {
        $state = $this->get($chat)->state;
        $memory = empty($state['temporary']) ? app(MemoryService::class)->values($buyer) : [];
        $defaults = array_intersect_key($memory, array_flip(['city', 'address', 'fulfillment']));
        $intent = array_replace_recursive($defaults, $state['intent'] ?? []);
        if (count($memory['vehicles'] ?? []) === 1 && empty($intent['car_model_id']) && empty($intent['query']) && empty($state['temporary'])
            && (! isset($intent['year']) || $intent['year'] === ($memory['vehicles'][0]['year'] ?? null))) {
            $vehicle = $memory['vehicles'][0];
            $intent = array_replace(array_intersect_key($vehicle, array_flip(['car_model_id', 'year', 'options'])), $intent);
        }

        return ['intent' => $intent, 'stage' => $state['stage'] ?? 'vehicle', 'selected' => $state['selected'] ?? [], 'lines' => array_values($state['lines'] ?? []), 'awaiting_option' => $state['awaiting_option'] ?? null];
    }

    public function selectOffer(Conversation $chat, BuyerContext $buyer, int $offerId, array $legacyOffer): void
    {
        DB::transaction(function () use ($chat, $buyer, $offerId, $legacyOffer) {
            $chat = Conversation::query()->whereKey($chat->id)->where('owner_key', ChatIdentity::ownerKey($buyer->fresh()))->lockForUpdate()->firstOrFail();
            if ($chat->active_turn_id) {
                throw new PurchaseException('CONVERSATION_BUSY', 409);
            }
            $session = $this->get($chat);
            $state = $session->state;
            $offer = array_key_exists('search', $state) ? collect($state['search']['items'] ?? [])->firstWhere('offer_id', $offerId) : $legacyOffer;
            if (! $offer || ! OfferVehicleConstraints::allows($offer, $state['intent'] ?? [])) {
                throw new PurchaseException('OFFER_SELECTION_REQUIRED', 409);
            }
            $state['selected'] = [$offerId];
            $state['intent']['offer_id'] = $offerId;
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
        });
    }

    public function answerOption(Conversation $chat, BuyerContext $buyer, string $flag, ?bool $answer): void
    {
        DB::transaction(function () use ($chat, $buyer, $flag, $answer) {
            $owned = Conversation::query()->whereKey($chat->id)->where('owner_key', ChatIdentity::ownerKey($buyer->fresh()))->lockForUpdate()->firstOrFail();
            if ($owned->active_turn_id !== null) {
                throw new PurchaseException('TURN_NOT_ACTIVE', 409);
            }
            $session = $this->get($owned);
            $state = $session->state;
            if (($state['awaiting_option'] ?? null) !== $flag) {
                throw new PurchaseException('OPTION_CHANGED', 409);
            }
            $state['intent']['options'][$flag] = $answer;
            $state['awaiting_option'] = null;
            $state['selected'] = [];
            unset($state['intent']['offer_id']);
            if ($answer === null) {
                $state['stage'] = 'manager_review';
            }
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
            $this->syncVehicleDraft($session, $buyer, $state['intent']);
            app(MemoryService::class)->remember($buyer, $state['intent'], (bool) ($state['temporary'] ?? false));
        });
    }

    public function useVehicle(Conversation $chat, BuyerContext $buyer, string $id): array
    {
        return DB::transaction(function () use ($chat, $buyer, $id) {
            $owned = Conversation::query()->whereKey($chat->id)->where('owner_key', ChatIdentity::ownerKey($buyer->fresh()))->lockForUpdate()->firstOrFail();
            if ($owned->active_turn_id !== null) {
                throw new PurchaseException('CONVERSATION_BUSY', 409);
            }
            $vehicle = collect(app(MemoryService::class)->values($buyer)['vehicles'] ?? [])->firstWhere('id', $id);
            abort_unless($vehicle, 404);
            $session = $this->get($owned);
            $state = $session->state;
            $state['intent'] = array_replace(array_diff_key($state['intent'] ?? [], array_flip(['car_model_id', 'brand_id', 'year', 'generation_id', 'body', 'doors', 'options', 'offer_id'])),
                array_intersect_key($vehicle, array_flip(['car_model_id', 'year', 'options'])));
            $state['intent']['query'] = $vehicle['label'];
            $state['selected'] = $state['search'] = [];
            $state['awaiting_option'] = null;
            $state['temporary'] = false;
            $session->forceFill(['state' => $state, 'version' => $session->version + 1])->save();
            $this->syncVehicleDraft($session, $buyer, $state['intent']);

            return $vehicle;
        });
    }

    private function syncVehicleDraft(ShoppingSession $session, BuyerContext $buyer, array $intent): void
    {
        $draft = $session->draft_id ? DraftAccess::query($buyer, $session->draft_id)->where('status', 'active')->lockForUpdate()->first() : null;
        if ($draft) {
            $patch = ['vehicle' => ['model_id' => $intent['car_model_id'] ?? null,
                'year' => $intent['year'] ?? null, 'generation_id' => null, 'body' => null, 'doors' => null, 'options' => $intent['options'] ?? []]];
            if ($draft->sales_mode === SalesMode::Retail) {
                $patch['items'] = [];
            }
            app(DraftService::class)->update($buyer, $draft->id, $draft->version, $patch);
        }
    }
}
