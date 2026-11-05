<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Runtime\ChatIdentity;
use App\Ai\Runtime\ChatStore;
use App\Ai\Runtime\Conversation;
use App\Ai\Runtime\MessageText;
use App\Domain\Assistant\ProcurementService;
use App\Domain\Assistant\ShoppingService;
use App\Domain\Catalog\SearchOffers;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\CustomerPurchases;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\PurchaseCommands;
use App\Domain\Shared\PurchaseException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class JourneyTools
{
    public static function declarations(): array
    {
        $empty = ['type' => 'object', 'properties' => new \stdClass];
        $order = ['type' => 'object', 'properties' => [
            'order_token' => ['type' => 'string', 'description' => 'ID из list_orders; не угадывать'],
            'order_number' => ['type' => 'string', 'description' => 'Номер, который назвал покупатель: АС-000078 или 78'],
        ]];

        return [
            ...array_map(fn ($name) => ['name' => $name, 'description' => $name === 'compare_offers' ? 'Сравнить до трёх показанных предложений по актуальным цене, наличию и комплектации.' : 'Проверить применимость показанных предложений к текущему автомобилю. Неизвестные опции требуют уточнения.', 'parameters' => ['type' => 'object', 'properties' => ['offer_ids' => ['type' => 'array', 'items' => ['type' => 'integer']]], 'required' => ['offer_ids']]], ['compare_offers', 'check_fitment']),
            ['name' => 'list_orders', 'description' => 'Свои последние заказы после входа.', 'parameters' => $empty],
            ['name' => 'get_order', 'description' => 'Текущий статус своего заказа и доступные действия.', 'parameters' => $order],
            ['name' => 'get_draft', 'description' => 'Текущий серверный черновик диалога.', 'parameters' => $empty],
            ['name' => 'update_draft', 'description' => 'Изменить количество конкретной строки по просьбе клиента.', 'parameters' => ['type' => 'object', 'properties' => ['line_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer'], 'operation' => ['type' => 'string', 'enum' => ['set', 'add', 'remove']], 'expected_version' => ['type' => 'integer']], 'required' => ['line_id', 'quantity', 'operation', 'expected_version']]],
            ['name' => 'quote_draft', 'description' => 'Расчёт текущего черновика; покупка подтверждается кнопкой.', 'parameters' => $empty],
            ['name' => 'prepare_repeat', 'description' => 'Новый черновик по своему заказу. Не подтверждает покупку.', 'parameters' => $order],
            ['name' => 'prepare_change_request', 'description' => 'Форма изменения своего заказа для отправки клиентом.', 'parameters' => $order],
            ['name' => 'cancel_order', 'description' => 'Показать отмену своего заказа. Не отменяет: покупатель подтверждает кнопкой.', 'parameters' => $order],
            ['name' => 'pay_order', 'description' => 'Показать оплату своего заказа. Не списывает деньги: покупатель нажимает «Оплатить».', 'parameters' => $order],
        ];
    }

    public function call(string $name, array $args, BuyerContext $buyer, ?string $turnId): array
    {
        $purchases = app(CustomerPurchases::class);
        if ($name === 'list_orders') {
            return ['orders' => $purchases->recent($buyer)];
        }
        if (in_array($name, ['get_order', 'prepare_change_request', 'cancel_order', 'pay_order'], true)) {
            $order = self::order($purchases, $buyer, $args);
            $result = ['order' => $purchases->view($order), 'url' => route('purchase.show', $order->public_token)];
            if ($name === 'cancel_order') {
                // Только показываем, можно ли: отменяет человек кнопкой, не модель.
                $result['cancel'] = ['allowed' => $result['order']['can_cancel'], 'code' => $purchases->cancelBlocker($order)];
            }

            return $result;
        }

        return DB::transaction(function () use ($name, $args, $buyer, $turnId, $purchases) {
            $turn = $turnId ? DB::table('ai_turns')->where('id', $turnId)->first() : null;
            $chat = $turn ? Conversation::query()->whereKey($turn->conversation_id)->where('owner_key', ChatIdentity::ownerKey($buyer))->lockForUpdate()->first() : null;
            if (! $chat || $chat->active_turn_id !== $turnId || ! app(ChatStore::class)->active($turnId)) {
                throw new PurchaseException('TURN_NOT_ACTIVE', 409);
            }
            $session = app(ShoppingService::class)->get($chat);
            if (in_array($name, ['compare_offers', 'check_fitment'], true)) {
                $ids = $args['offer_ids'] ?? [];
                $known = array_column($session->state['search']['items'] ?? [], 'offer_id');
                if (! is_array($ids) || $ids === [] || array_diff($ids, $known)) {
                    throw new PurchaseException('OFFER_SELECTION_REQUIRED', 422);
                }
                $intent = app(ShoppingService::class)->context($chat, $buyer)['intent'];
                $vehicle = array_intersect_key($intent, array_flip(['car_model_id', 'year', 'generation_id', 'body', 'doors', 'options']));

                return app(SearchOffers::class)->search($buyer, ['offer_ids' => $ids, ...$vehicle]);
            }
            if ($name === 'prepare_repeat') {
                $draft = $purchases->repeat($buyer, self::order($purchases, $buyer, $args)->public_token, 'ai-repeat:'.$turn->operation_id);
                app(ProcurementService::class)->replace($buyer, $chat->id, array_map(fn ($line) => ['article' => $line['reference'], 'quantity' => $line['quantity'], 'manufacturer' => $line['manufacturer'] ?? ''], $draft['items']));
                $session->forceFill(['draft_id' => $draft['id']])->save();

                return ['draft' => $this->safeDraft($draft)];
            }
            if (! $session->draft_id) {
                return ['code' => 'DRAFT_REQUIRED'];
            }
            $draft = app(DraftService::class)->get($buyer, $session->draft_id)->jsonSerialize();
            if ($name === 'get_draft') {
                return ['draft' => $this->safeDraft($draft)];
            }
            if ($name === 'update_draft') {
                $question = MessageText::decode((string) DB::table('ai_messages')->where('id', $turn->user_message_id)->value('text'));
                $operation = match (true) {
                    preg_match('/добав|ещ[её]|қос/ui', $question) === 1 => 'add',
                    preg_match('/удал|убери|алып\s+таста|жой/ui', $question) === 1 => 'remove',
                    preg_match('/количеств|поставь|сделай|санын|өзгерт/ui', $question) === 1 => 'set',
                    default => null,
                };
                if ($operation === null || ($args['operation'] ?? '') !== $operation || ! is_int($args['quantity'] ?? null)
                    || $args['quantity'] < 1 || $args['quantity'] > 99
                    || ($operation !== 'remove' && ! preg_match('/(?<!\d)'.preg_quote((string) $args['quantity'], '/').'(?!\d)/u', $question))) {
                    throw new PurchaseException('INVALID_DRAFT_CHANGE', 422);
                }
                $lineId = $args['line_id'] ?? (count($draft['items']) === 1 ? $draft['items'][0]['line_id'] : null);
                $items = [];
                $found = false;
                foreach ($draft['items'] as $line) {
                    if ($line['line_id'] === $lineId) {
                        $found = true;
                        if ($operation === 'remove') {
                            continue;
                        }
                        $line['quantity'] = $operation === 'add' ? $line['quantity'] + $args['quantity'] : $args['quantity'];
                    }
                    $items[] = array_intersect_key($line, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'comment', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed']));
                }
                $expectedVersion = $args['expected_version'] ?? $draft['version'];
                if (! $found || ! is_int($expectedVersion)) {
                    throw new PurchaseException('LINE_SELECTION_REQUIRED', 422);
                }
                $draft = app(PurchaseCommands::class)->updateDraft($buyer, $draft['id'], 'ai-update:'.$turn->operation_id, $expectedVersion, ['items' => $items])->data;

                return ['draft' => $this->safeDraft($draft)];
            }
            if ($name === 'quote_draft') {
                return app(AssistantCheckout::class)->quoteExisting($buyer, $draft['id'], $turnId);
            }

            return ['code' => 'UNKNOWN_TOOL'];
        });
    }

    /** Токен из list_orders надёжнее, но человек обычно называет номер. */
    private static function order(CustomerPurchases $purchases, BuyerContext $buyer, array $args): Order
    {
        $token = (string) ($args['order_token'] ?? '');
        if ($token !== '') {
            return $purchases->owned($buyer, $token);
        }

        return $purchases->ownedByNumber($buyer, (string) ($args['order_number'] ?? ''));
    }

    private function safeDraft(array $draft): array
    {
        unset($draft['contacts'], $draft['fulfillment']['address']);

        return $draft;
    }
}
