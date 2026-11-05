<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Models\AiCheckout;
use App\Ai\Runtime\ChatIdentity;
use App\Domain\Assistant\ShoppingSession;
use App\Domain\Fulfillment\QuoteFulfillment;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\DraftAccess;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Ordering\SalesMode;
use App\Domain\Shared\CanonicalJson;
use App\Domain\Shared\PurchaseException;
use App\Enums\OrderSource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ServicePoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** No session dependency. A model can prepare; only the UI adapter calls confirm. */
final class AssistantCheckout
{
    public function __construct(private readonly PurchaseCommands $commands, private readonly DraftService $drafts) {}

    public function prepare(BuyerContext $buyer, array $args, ?string $turnId, ?array $trustedItems = null): array
    {
        Validator::make(['turn_id' => $turnId], ['turn_id' => ['required', 'uuid']])->validate();
        // Ignore neither client prices nor product IDs: a wrong identifier must be corrected.
        if (array_diff(array_keys($args), ['name', 'phone', 'offer_id', 'reference', 'quantity', 'items', 'fulfillment', 'city', 'address', 'service_point_id', 'slot_start', 'slot_end', 'comment', 'privacy_confirmed', ...array_keys(AssistantTools::vehicleProperties())])) {
            throw ValidationException::withMessages(['_input' => 'Неизвестное поле; нужен offer_id, не product_id.']);
        }
        unset($args['privacy_confirmed']); // Model assertions can never stand in for a checkbox click.
        try {
            $hash = hash_hmac('sha256', CanonicalJson::encode($args), (string) config('app.key'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['_input' => 'Неверный тип значения; идентификаторы и количество должны быть целыми числами.']);
        }

        return DB::transaction(function () use ($buyer, $args, $turnId, $hash, $trustedItems) {
            $buyer = $buyer->fresh();
            $operationId = $this->activeOperation($buyer, $turnId);
            $scope = $this->scope($buyer);
            DB::table('ai_checkouts')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'owner_scope' => $scope, 'turn_id' => $operationId,
                'request_hash' => $hash, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $checkout = AiCheckout::query()->where('owner_scope', $scope)->where('turn_id', $operationId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($checkout->request_hash, $hash)) {
                throw new PurchaseException('CHECKOUT_ALREADY_PREPARED', 409, ['note' => 'Для изменения данных нужен новый запрос клиента.']);
            }
            if ($checkout->snapshot !== null) {
                return $this->prepared($checkout);
            }

            $vehicle = array_intersect_key($args, array_flip(array_keys(AssistantTools::vehicleProperties())));
            if (array_key_exists('car_model_id', $vehicle)) {
                $vehicle['model_id'] = $vehicle['car_model_id'];
                unset($vehicle['car_model_id']);
            }
            $fulfillment = ['type' => $args['fulfillment'] ?? 'pickup', 'city' => $args['city'] ?? settings()->defaultCity()];
            foreach (['address', 'service_point_id', 'slot_start', 'slot_end'] as $field) {
                if (array_key_exists($field, $args)) {
                    $fulfillment[$field] = $args[$field];
                }
            }
            // A single point is unambiguous and remains visible on the confirmation card.
            if (! isset($fulfillment['service_point_id']) && is_string($fulfillment['city']) && in_array($fulfillment['type'], ['pickup', 'installation'], true)) {
                $points = ServicePoint::query()->where('is_active', true)->where('city', $fulfillment['city'])->limit(2)->get();
                if ($points->count() === 1) {
                    $fulfillment['service_point_id'] = $points->first()->id;
                }
            }
            $items = [];
            if (! empty($args['items']) && is_array($args['items'])) {
                foreach ($args['items'] as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $items[] = [
                        'line_id' => (string) Str::uuid(),
                        'offer_id' => $line['offer_id'] ?? null,
                        'reference' => $line['reference'] ?? null,
                        'quantity' => $line['quantity'] ?? 1,
                        'comment' => isset($line['comment']) ? (string) $line['comment'] : ($args['comment'] ?? null),
                        // Exact-article confirmations are accepted only from the trusted UI path below.
                        'selection_mode' => 'vehicle_fitment',
                    ];
                }
            }
            if ($items === []) {
                $items = [[
                    'line_id' => (string) Str::uuid(), 'offer_id' => $args['offer_id'] ?? null,
                    'reference' => $args['reference'] ?? null, 'quantity' => $args['quantity'] ?? 1,
                    'comment' => $args['comment'] ?? null, 'selection_mode' => 'vehicle_fitment',
                ]];
            }
            if ($trustedItems !== null) {
                $items = array_map(fn ($item) => ['line_id' => (string) Str::uuid(), ...$item], $trustedItems);
            }
            $conversationId = DB::table('ai_turns')->where('id', $turnId)->value('conversation_id');
            $session = ShoppingSession::query()->find($conversationId);
            $ownedDraft = $session?->draft_id ? DraftAccess::query($buyer, $session->draft_id)->where('status', 'active')->first() : null;
            $draft = $ownedDraft ? $this->drafts->get($buyer, $ownedDraft->id)->jsonSerialize() : app(ActiveDraft::class)->current($buyer, true)->jsonSerialize();
            if (! $ownedDraft && ShoppingSession::query()->where('draft_id', $draft['id'])->where('conversation_id', '!=', $conversationId)->exists()) {
                $draft = $this->drafts->create($buyer, $buyer->wholesaleApproved ? SalesMode::Wholesale : SalesMode::Retail)->jsonSerialize();
            }
            app(ActiveDraft::class)->bind($buyer, $draft['id']);
            $merged = [];
            foreach ($draft['items'] as $existing) {
                $merged[$existing['offer_id'] ? 'offer:'.$existing['offer_id'] : 'line:'.$existing['line_id']] = array_intersect_key($existing, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed', 'comment']));
            }
            foreach ($items as $item) {
                $key = $item['offer_id'] ? 'offer:'.$item['offer_id'] : 'line:'.$item['line_id'];
                $merged[$key] = $item;
            }
            $items = array_values($merged);
            $draft = $this->commands->updateDraft($buyer, $draft['id'], 'ai:'.$checkout->id.':update', $draft['version'], [
                'contacts' => ['name' => $args['name'] ?? null, 'phone' => $args['phone'] ?? null],
                'vehicle' => $vehicle, 'fulfillment' => $fulfillment,
                'items' => $items,
            ])->data;
            $quote = $this->commands->prepareQuote($buyer, $draft['id'], 'ai:'.$checkout->id.':quote', $draft['version'])->data;
            if ($quote['readiness'] === 'incomplete' && ! $this->editableIncomplete($quote)) {
                // Roll back the entire preparation so the model can correct arguments in this turn.
                throw new PurchaseException('QUOTE_INCOMPLETE', 422, ['reasons' => $quote['reasons'], 'issues' => $quote['issues']]);
            }
            $checkout->snapshot = $this->snapshot($quote);
            $checkout->save();
            $conversationId = DB::table('ai_turns')->where('id', $turnId)->value('conversation_id');
            ShoppingSession::query()->whereKey($conversationId)->update(['draft_id' => $draft['id']]);

            return $this->prepared($checkout);
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    public function pendingForTurns(BuyerContext $buyer, array $turnIds): array
    {
        return AiCheckout::query()->where('owner_scope', $this->scope($buyer->fresh()))
            ->whereIn('turn_id', array_slice($turnIds, -100))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('ai_turns as t')
                ->whereColumn('t.operation_id', 'ai_checkouts.turn_id')->where('t.status', 'completed')
                ->whereNotExists(fn ($later) => $later->selectRaw('1')->from('ai_turns as newer')
                    ->whereColumn('newer.operation_id', 't.operation_id')->whereColumn('newer.attempt', '>', 't.attempt')))
            ->orderByDesc('created_at')->limit(10)->get()->reverse()
            ->map(fn (AiCheckout $checkout) => $this->card($checkout))->all();
    }

    public function quoteExisting(BuyerContext $buyer, string $draftId, string $turnId): array
    {
        return DB::transaction(function () use ($buyer, $draftId, $turnId) {
            $buyer = $buyer->fresh();
            $operation = $this->activeOperation($buyer, $turnId);
            $draft = $this->drafts->get($buyer, $draftId);
            $hash = hash_hmac('sha256', $draftId.':'.$draft->version, (string) config('app.key'));
            $checkout = AiCheckout::query()->firstOrCreate(['owner_scope' => $this->scope($buyer), 'turn_id' => $operation], ['id' => (string) Str::uuid(), 'status' => 'pending', 'request_hash' => $hash]);
            if (! hash_equals($checkout->request_hash, $hash)) {
                throw new PurchaseException('CHECKOUT_ALREADY_PREPARED', 409);
            }
            if ($checkout->snapshot === null) {
                $quote = $this->commands->prepareQuote($buyer, $draftId, 'ai-existing:'.$operation, $draft->version)->data;
                if ($quote['readiness'] === 'incomplete' && ! $this->editableIncomplete($quote)) {
                    throw new PurchaseException('QUOTE_INCOMPLETE', 422, ['reasons' => $quote['reasons']]);
                }
                $checkout->snapshot = $this->snapshot($quote);
                $checkout->save();
            }

            return $this->prepared($checkout);
        });
    }

    public function confirm(BuyerContext $buyer, string $checkoutId, bool $privacyConsent, string $expectedQuoteId): array
    {
        return DB::transaction(function () use ($buyer, $checkoutId, $privacyConsent, $expectedQuoteId) {
            $buyer = $buyer->fresh();
            $checkout = $this->owned($buyer, $checkoutId);
            if ($checkout->result !== null) {
                return $checkout->result;
            }
            if (settings()->enabled('privacy_consent') && ! $privacyConsent) {
                throw new PurchaseException('PRIVACY_CONSENT_REQUIRED', 422);
            }
            $data = $checkout->snapshot;
            if (! hash_equals($data['quote_id'], $expectedQuoteId)) {
                throw new PurchaseException('CHECKOUT_CHANGED', 409);
            }
            $result = $this->commands->submitDraft($buyer, $data['draft_id'], 'ai:'.$checkout->id.':submit:'.$data['quote_id'], $data['quote_id'], $data['draft_version'], $data['action'])->data;
            // The checkout owns this newly submitted draft; its UUID is generated only by this service.
            Order::query()->whereKey($result['order_id'])->update(['source' => OrderSource::Assistant->value]);
            if ($buyer->customerId !== null && ($customer = Customer::query()->find($buyer->customerId)) !== null) {
                $fulfillment = $data['fulfillment'] ?? [];
                $customer->rememberDelivery(
                    isset($fulfillment['address']) ? (string) $fulfillment['address'] : null,
                    isset($fulfillment['city']) ? (string) $fulfillment['city'] : null,
                    isset($fulfillment['type']) ? (string) $fulfillment['type'] : null,
                );
            }
            $checkout->forceFill(['status' => 'submitted', 'result' => $result, 'privacy_consented_at' => $privacyConsent ? now() : null])->save();

            return $result;
        }, 3);
    }

    /** Recalculate expired/changed facts; always requires a new explicit confirm click. */
    public function refresh(BuyerContext $buyer, string $checkoutId): array
    {
        return DB::transaction(function () use ($buyer, $checkoutId) {
            $buyer = $buyer->fresh();
            $checkout = $this->owned($buyer, $checkoutId);
            if ($checkout->result !== null) {
                return $this->card($checkout);
            }
            $draft = $this->drafts->get($buyer, $checkout->snapshot['draft_id']);
            $quote = $this->commands->prepareQuote($buyer, $draft->id, 'ai:'.$checkout->id.':refresh:'.Str::uuid(), $draft->version)->data;
            $checkout->snapshot = $this->snapshot($quote);
            $checkout->save();

            return $this->card($checkout);
        }, 3);
    }

    /** Update contact or fulfillment fields on a pending checkout card and recalculate the quote. */
    public function updateDetails(BuyerContext $buyer, string $checkoutId, array $details): array
    {
        return DB::transaction(function () use ($buyer, $checkoutId, $details) {
            $buyer = $buyer->fresh();
            $checkout = $this->owned($buyer, $checkoutId);
            if ($checkout->result !== null) {
                return $this->card($checkout);
            }
            $draft = $this->drafts->get($buyer, $checkout->snapshot['draft_id']);
            $currentContacts = $checkout->snapshot['contacts'] ?? [];
            $currentFulfillment = $checkout->snapshot['fulfillment'] ?? [];
            $type = (string) ($details['fulfillment'] ?? $currentFulfillment['type'] ?? 'pickup');
            $city = trim((string) ($details['city'] ?? $currentFulfillment['city'] ?? settings()->defaultCity()));
            $fulfillment = [
                'type' => $type,
                'city' => $city !== '' ? $city : settings()->defaultCity(),
            ];
            if ($type === 'delivery' || $type === 'visit') {
                $address = trim((string) ($details['address'] ?? $currentFulfillment['address'] ?? ''));
                if ($address !== '') {
                    $fulfillment['address'] = $address;
                }
            }
            if (in_array($type, ['pickup', 'installation'], true)) {
                $pointId = isset($details['service_point_id']) && (int) $details['service_point_id'] > 0
                    ? (int) $details['service_point_id']
                    : (isset($currentFulfillment['service_point_id']) ? (int) $currentFulfillment['service_point_id'] : null);
                if ($pointId === null) {
                    $points = ServicePoint::query()->where('is_active', true)->where('city', $fulfillment['city'])->limit(2)->get();
                    if ($points->count() === 1) {
                        $pointId = $points->first()->id;
                    }
                }
                if ($pointId !== null) {
                    $fulfillment['service_point_id'] = $pointId;
                }
            }
            foreach (['slot_start', 'slot_end'] as $slotField) {
                if (! empty($details[$slotField])) {
                    $fulfillment[$slotField] = (string) $details[$slotField];
                } elseif (in_array($type, ['installation', 'visit'], true) && ! empty($currentFulfillment[$slotField])) {
                    $fulfillment[$slotField] = (string) $currentFulfillment[$slotField];
                }
            }
            $name = trim((string) ($details['name'] ?? $currentContacts['name'] ?? ''));
            $phone = trim((string) ($details['phone'] ?? $currentContacts['phone'] ?? ''));
            $updated = $this->commands->updateDraft($buyer, $draft->id, 'ai:'.$checkout->id.':edit:'.Str::uuid(), $draft->version, [
                ...(! empty($details['payment_method']) ? ['payment_method' => $details['payment_method']] : []),
                'contacts' => [
                    'name' => $name !== '' ? $name : null,
                    'phone' => $phone !== '' ? $phone : null,
                ],
                'fulfillment' => $fulfillment,
            ])->data;
            $quote = $this->commands->prepareQuote($buyer, $updated['id'], 'ai:'.$checkout->id.':quote:'.Str::uuid(), $updated['version'])->data;
            if ($quote['readiness'] === 'incomplete' && ! $this->editableIncomplete($quote)) {
                throw new PurchaseException('QUOTE_INCOMPLETE', 422, ['reasons' => $quote['reasons'], 'issues' => $quote['issues']]);
            }
            $checkout->snapshot = $this->snapshot($quote);
            $checkout->save();

            return $this->card($checkout);
        }, 3);
    }

    private function owned(BuyerContext $buyer, string $id): AiCheckout
    {
        if (! Str::isUuid($id)) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }

        $query = AiCheckout::query()->whereKey($id)->where('owner_scope', $this->scope($buyer));
        $checkout = (clone $query)->first() ?? throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        $this->completedOperation($buyer, $checkout->turn_id);

        return $query->lockForUpdate()->firstOrFail();
    }

    /** Same lock order as cancellation/retry: conversation, checkout, domain commands. */
    private function activeOperation(BuyerContext $buyer, string $turnId): string
    {
        $turn = DB::table('ai_turns')->where('id', strtolower($turnId))->first();
        if (! $turn) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $chat = DB::table('ai_conversations')->where('id', $turn->conversation_id)
            ->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->first();
        if (! $chat) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $turn = DB::table('ai_turns')->where('id', $turn->id)->first();
        if ($chat->active_turn_id !== $turn->id || ! in_array($turn->status, ['running', 'queued'], true) || now()->greaterThanOrEqualTo($turn->deadline_at)) {
            throw new PurchaseException('TURN_NOT_ACTIVE', 409);
        }

        return $turn->operation_id;
    }

    private function completedOperation(BuyerContext $buyer, string $operationId): void
    {
        $turn = DB::table('ai_turns')->where('operation_id', $operationId)->orderByDesc('attempt')->first();
        if (! $turn) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $chat = DB::table('ai_conversations')->where('id', $turn->conversation_id)
            ->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->first();
        if (! $chat) {
            throw new PurchaseException('RESOURCE_NOT_FOUND', 404);
        }
        $latest = DB::table('ai_turns')->where('operation_id', $operationId)->orderByDesc('attempt')->first();
        if ($latest->status !== 'completed') {
            throw new PurchaseException('TURN_NOT_COMPLETED', 409);
        }
    }

    private function scope(BuyerContext $buyer): string
    {
        return hash('sha256', $buyer->customerId !== null ? 'customer:'.$buyer->customerId : 'guest:'.$buyer->guestOwnerHash);
    }

    private function snapshot(array $quote): array
    {
        $wholesale = ($quote['sales_mode'] ?? '') === 'wholesale';

        return [...$quote, 'quote_id' => $quote['id'], 'action' => $quote['readiness'] === 'ready' && config($wholesale ? 'ordering.wholesale_auto_checkout_enabled' : 'ordering.auto_checkout_enabled') ? ($wholesale ? 'confirm_wholesale' : 'confirm_retail') : 'submit_for_review'];
    }

    private function editableIncomplete(array $quote): bool
    {
        return ! array_filter($quote['issues'] ?? [], fn ($issue) => ($issue['severity'] ?? '') === 'incomplete'
            && ! in_array($issue['code'], ['SLOT_REQUIRED', 'SERVICE_POINT_REQUIRED', 'CITY_REQUIRED', 'ADDRESS_REQUIRED'], true));
    }

    public function slots(BuyerContext $buyer, string $checkoutId, int $pointId, string $date): array
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        return DB::transaction(function () use ($buyer, $checkoutId, $pointId, $date) {
            $checkout = $this->owned($buyer, $checkoutId);
            $point = ServicePoint::query()->whereKey($pointId)->where('is_active', true)->first();
            $duration = (int) ($checkout->snapshot['fulfillment']['duration_minutes'] ?? 0);
            if (! $point || ! in_array($point->timezone, \DateTimeZone::listIdentifiers(), true) || $duration <= 0 || $duration > 1440 || $checkout->status === 'submitted') {
                return [];
            }
            $at = CarbonImmutable::now('UTC');
            $cursor = CarbonImmutable::parse($date, $point->timezone)->setTimeFromTimeString($point->opens_at);
            $closing = $cursor->setTimeFromTimeString($point->closes_at);
            $step = max(1, (int) config('booking.step_minutes', 30));
            $slots = [];
            while ($cursor->addMinutes($duration)->lte($closing) && count($slots) < 12) {
                $selection = ['slot_start' => $cursor->toIso8601String(), 'slot_end' => $cursor->addMinutes($duration)->toIso8601String()];
                if (app(QuoteFulfillment::class)->slotIssues($selection, $point, $duration, $at)->all() === []) {
                    $slots[] = [...$selection, 'service_point_id' => $point->id, 'label' => $cursor->format('d.m.Y H:i').'–'.$cursor->addMinutes($duration)->format('H:i').' ('.$point->timezone.')'];
                }
                $cursor = $cursor->addMinutes($step);
            }

            return $slots;
        });
    }

    private function card(AiCheckout $checkout): array
    {
        $data = $checkout->snapshot ?? [];
        $data['service_points'] = ServicePoint::query()->where('is_active', true)->where('city', $data['fulfillment']['city'] ?? '')->limit(30)->get(['id', 'name', 'address', 'city'])->toArray();
        $data['stale'] = $checkout->status !== 'submitted' && isset($data['draft_id'])
            && OrderDraft::query()->whereKey($data['draft_id'])->where('version', '!=', $data['draft_version'] ?? 0)->exists();

        return [...$data, 'id' => $checkout->id, 'turn_id' => $checkout->turn_id, 'status' => $checkout->status, 'result' => $checkout->result];
    }

    private function prepared(AiCheckout $checkout): array
    {
        return ['ok' => true, 'checkout' => $this->card($checkout), 'note' => $checkout->result !== null
            ? 'Клиент уже подтвердил эту заявку. Номер: '.($checkout->result['order_number'] ?? '')
            : 'Расчёт подготовлен. Клиент должен проверить карточку и нажать кнопку подтверждения. Заявка ещё не отправлена.'];
    }
}
