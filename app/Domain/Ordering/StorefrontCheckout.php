<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Cart\Cart;
use App\Domain\Identity\BuyerContext;
use App\Domain\Identity\SessionBuyer;
use App\Domain\Shared\Commands\CommandResult;
use App\Domain\Shared\Commands\Idempotency;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServicePoint;
use Illuminate\Support\Str;

/**
 * Витрина поверх нового ядра заказов.
 *
 * Пока ORDERING_STOREFRONT_CORE выключен, формы идут через legacy CreateOrder.
 * Здесь тот же сценарий собирается черновиком: позиции корзины, контакты и
 * способ получения кладутся в draft, считается котировка и отправляется на
 * согласование менеджеру. Два ядра живут параллельно ровно до тех пор, пока
 * регрессии не подтвердят равенство правил — тогда legacy-ветку снимают.
 */
final class StorefrontCheckout
{
    public function __construct(private readonly PurchaseCommands $commands) {}

    public static function enabled(): bool
    {
        return (bool) config('ordering.storefront_core', false);
    }

    /**
     * Оформление корзины черновиком. Возвращает созданный заказ.
     *
     * @param  array{name:string,phone:string,fulfillment?:?string,address?:?string,city?:?string,comment?:?string,payment_method?:?string,form_token?:?string,privacy_consent?:?string}  $data
     */
    public function fromCart(array $data, Cart $cart): Order
    {
        $items = [];

        foreach ($cart->items() as $item) {
            $items[] = [
                'line_id' => (string) Str::uuid(),
                'offer_id' => $item->offer?->id,
                'reference' => $item->offer === null
                    ? ($item->product->eurocode ?: 'product:'.$item->product->id)
                    : null,
                'quantity' => $item->quantity,
            ];
        }

        return $this->checkout($data, $items);
    }

    /**
     * Заявка из карточки товара черновиком: одна позиция, лучший активный оффер.
     *
     * @param  array<string,mixed>  $data
     */
    public function forProduct(array $data, Product $product): Order
    {
        $buyer = SessionBuyer::current();
        $offer = $product->offers()
            ->where('is_active', true)
            ->orderBy('price_retail')
            ->first();

        return $this->checkout($data, [[
            'line_id' => (string) Str::uuid(),
            'offer_id' => $offer?->id,
            'reference' => $offer === null
                ? ($product->eurocode ?: 'product:'.$product->id)
                : null,
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
        ]], $buyer);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,array{line_id:string,offer_id:?int,reference:?string,quantity:int}>  $items
     */
    private function checkout(array $data, array $items, ?BuyerContext $buyer = null): Order
    {
        $buyer ??= SessionBuyer::current();

        // form_token — готовый ключ идемпотентности: повторная отправка той же
        // формы вернёт уже созданный заказ, а не заведёт дубль.
        $key = 'storefront-'.(trim((string) ($data['form_token'] ?? '')) ?: (string) Str::uuid());

        $inputItems = array_map(fn ($line) => array_diff_key($line, ['line_id' => true]), $items);
        $result = app(Idempotency::class)->execute($buyer, 'storefront.checkout.v2', $key, ['data' => $data, 'items' => $inputItems],
            fn () => null,
            function (BuyerContext $buyer) use ($data, $items, $key) {
                $order = $this->submitCurrent($buyer, $data, $items, $key);

                return new CommandResult(['id' => $order->public_token, 'order_id' => $order->id], 201);
            });

        return Order::query()->findOrFail($result->data['order_id']);
    }

    private function submitCurrent(BuyerContext $buyer, array $data, array $items, string $key): Order
    {
        $draft = app(ActiveDraft::class)->current($buyer, true);
        $draftId = $draft->id;
        $version = $draft->version;
        $merged = [];
        foreach ($draft->jsonSerialize()['items'] as $line) {
            $merged[$line['offer_id'] ? 'offer:'.$line['offer_id'] : 'line:'.$line['line_id']] = array_intersect_key($line, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'comment', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed']));
        }
        foreach ($items as $line) {
            $lineKey = $line['offer_id'] ? 'offer:'.$line['offer_id'] : 'line:'.$line['line_id'];
            $merged[$lineKey] = array_replace($merged[$lineKey] ?? [], $line);
        }

        $changes = [
            'items' => array_values($merged),
            'contacts' => [
                'name' => trim((string) ($data['name'] ?? '')),
                'phone' => (string) ($data['phone'] ?? ''),
            ],
            'fulfillment' => array_filter([
                'type' => $data['fulfillment'] ?? 'pickup',
                'city' => $data['city'] ?? currentCity(),
                'address' => $data['address'] ?? null,
            ], fn ($value) => $value !== null),
        ];
        if (! empty($data['payment_method'])) {
            $changes['payment_method'] = $data['payment_method'];
        }
        if (in_array($changes['fulfillment']['type'], ['pickup', 'installation'], true)) {
            $points = ServicePoint::query()->where('is_active', true)->where('city', $changes['fulfillment']['city'])->limit(2)->get();
            if ($points->count() === 1) {
                $changes['fulfillment']['service_point_id'] = $points->first()->id;
            }
        }

        $updated = $this->commands->updateDraft($buyer, $draftId, $key.':update', $version, $changes);
        $version = (int) $updated->data['version'];

        $quote = $this->commands->prepareQuote($buyer, $draftId, $key.':quote', $version);

        $result = $this->commands->submitDraft(
            $buyer,
            $draftId,
            $key.':submit',
            (string) $quote->data['id'],
            (int) $quote->data['draft_version'],
            'submit_for_review',
        );

        $order = Order::query()->findOrFail((int) $result->data['order_id']);

        // Privacy acceptance and the form replay token belong to this explicit storefront submission.
        $order->forceFill(array_filter([
            'privacy_accepted_at' => empty($data['privacy_consent']) ? null : now(),
            'form_token' => trim((string) ($data['form_token'] ?? '')) ?: null,
        ], fn ($value) => $value !== null))->save();

        return $order;
    }
}
