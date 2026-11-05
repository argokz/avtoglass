<?php

namespace App\Cart;

use App\Domain\Identity\SessionBuyer;
use App\Domain\Ordering\ActiveDraft;
use App\Domain\Ordering\DraftService;
use App\Domain\Ordering\StorefrontCheckout;
use App\Enums\FulfillmentType;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Support\Str;

/**
 * Корзина покупателя, живущая в сессии.
 *
 * В сессии лежат id товаров, выбранный оффер и количества — сами товары
 * каждый раз достаются из базы. Между «положил» и «оформил» карточка может
 * уехать с витрины или подорожать, и корзина обязана это показать.
 */
class Cart
{
    private ?int $sharedVersion = null;

    private function shared(): bool
    {
        return StorefrontCheckout::enabled() && Schema::hasTable('purchase_workspaces');
    }

    private const KEY = 'cart';

    /** Стекло объёмное: заказ на сотню штук у розницы — это ошибка ввода. */
    public const MAX_QUANTITY = 20;

    /** Сервису иногда нужно стекло на несколько машин сразу. */
    public const MAX_WHOLESALE_QUANTITY = 99;

    public static function maxQuantity(): int
    {
        return текущийПокупатель()?->seesWholesalePrices() === true
            ? self::MAX_WHOLESALE_QUANTITY
            : self::MAX_QUANTITY;
    }

    public function __construct(private readonly Session $session) {}

    public function add(Product $product, int $quantity = 1, ?int $offerId = null): void
    {
        if (! $product->is_published) {
            return;
        }

        $offerId = $this->sanitizeOfferId($product, $offerId);
        $entries = $this->entries();
        $key = $this->key($product->id, $offerId);
        $current = $entries[$key]['qty'] ?? 0;

        $entries[$key] = [
            'product_id' => $product->id,
            'offer_id' => $offerId,
            'qty' => $this->clamp($current + $quantity),
        ];

        $this->save($entries);
    }

    public function setQuantity(Product $product, int $quantity, ?int $offerId = null): void
    {
        $entries = $this->entries();
        $key = $this->key($product->id, $this->sanitizeOfferId($product, $offerId));

        if ($quantity <= 0) {
            unset($entries[$key]);
        } else {
            $entries[$key] = [
                'product_id' => $product->id,
                'offer_id' => $this->sanitizeOfferId($product, $offerId),
                'qty' => $this->clamp($quantity),
            ];
        }

        $this->save($entries);
    }

    public function remove(Product $product, ?int $offerId = null): void
    {
        $entries = $this->entries();
        unset($entries[$this->key($product->id, $this->sanitizeOfferId($product, $offerId))]);
        $this->save($entries);
    }

    public function clear(): void
    {
        if ($this->shared()) {
            $buyer = SessionBuyer::current();
            if ($draft = app(ActiveDraft::class)->current($buyer)) {
                app(DraftService::class)->update($buyer, $draft->id, $draft->version, ['items' => []]);
            }
        }
        $this->session->forget(self::KEY);
        Once::flush();
    }

    /**
     * Содержимое корзины. Товары, ушедшие с витрины, тихо выпадают —
     * и из выдачи, и из самой сессии: предлагать оформить то, чего уже
     * нет в каталоге, нельзя.
     *
     * @return Collection<int,CartItem>
     */
    public function items(): Collection
    {
        return once(function (): Collection {
            $entries = $this->entries();

            if ($entries === []) {
                return collect();
            }

            $productIds = array_unique(array_column($entries, 'product_id'));
            $offerIds = array_values(array_filter(array_column($entries, 'offer_id')));

            $products = Product::whereIn('id', $productIds)
                ->where('is_published', true)
                ->get()
                ->keyBy('id');

            $offers = $offerIds === []
                ? collect()
                : Offer::whereIn('id', $offerIds)
                    ->where('is_active', true)
                    ->with('supplier')
                    ->get()
                    ->keyBy('id');

            $alive = [];

            foreach ($entries as $key => $entry) {
                $product = $products->get($entry['product_id']);

                if ($product === null) {
                    continue;
                }

                $offer = $entry['offer_id'] ? $offers->get($entry['offer_id']) : null;

                if ($entry['offer_id'] && ($offer === null || $offer->product_id !== $product->id)) {
                    $offer = null;
                }

                $alive[$key] = [
                    'product_id' => $product->id,
                    'offer_id' => $offer?->id,
                    'qty' => $entry['qty'],
                ];
            }

            if ($alive !== $entries && ! $this->shared()) {
                $this->save($alive);
            }

            return collect($alive)
                ->map(function (array $entry) use ($products, $offers) {
                    $offer = $entry['offer_id'] ? $offers->get($entry['offer_id']) : null;

                    return new CartItem($products[$entry['product_id']], $entry['qty'], $offer);
                })
                ->values();
        });
    }

    public function count(): int
    {
        return (int) array_sum(array_column($this->entries(), 'qty'));
    }

    public function isEmpty(): bool
    {
        return $this->entries() === [];
    }

    public function total(): float
    {
        return (float) $this->items()->sum(fn (CartItem $item) => $item->subtotal());
    }

    /** Есть ли позиции без цены — по таким менеджер называет сумму сам. */
    public function hasItemsWithoutPrice(): bool
    {
        return $this->items()->contains(fn (CartItem $item) => ! $item->hasPrice());
    }

    /** @return array<string,CartQuote> */
    public function quotes(): array
    {
        $quotes = [];

        foreach (FulfillmentType::cases() as $type) {
            $quotes[$type->value] = CartQuote::forCart($this, $type);
        }

        return $quotes;
    }

    /**
     * @return array<string,array{product_id:int,offer_id:?int,qty:int}>
     */
    private function entries(): array
    {
        if ($this->shared() && ($draft = app(ActiveDraft::class)->current(SessionBuyer::current()))) {
            $this->sharedVersion = $draft->version;
            $entries = [];
            $lines = $draft->jsonSerialize()['items'];
            $offers = Offer::query()->whereIn('id', array_filter(array_column($lines, 'offer_id')))->get(['id', 'product_id'])->keyBy('id');
            foreach ($lines as $line) {
                $offer = $offers->get($line['offer_id']);
                $productId = $offer?->product_id;
                if (! $productId && preg_match('/^product:(\d+)$/D', $line['reference'] ?? '', $m)) {
                    $productId = (int) $m[1];
                }
                if ($productId) {
                    $entries[$this->key($productId, $offer?->id)] = ['product_id' => $productId, 'offer_id' => $offer?->id, 'qty' => $line['quantity']];
                }
            }

            return $entries;
        }
        $raw = (array) $this->session->get(self::KEY, []);
        $entries = [];

        foreach ($raw as $key => $value) {
            $parsed = $this->parseEntry($key, $value);

            if ($parsed === null || $parsed['qty'] <= 0) {
                continue;
            }

            $entries[$this->key($parsed['product_id'], $parsed['offer_id'])] = $parsed;
        }

        return $entries;
    }

    /** @return array{product_id:int,offer_id:?int,qty:int}|null */
    private function parseEntry(mixed $key, mixed $value): ?array
    {
        if (is_array($value) && isset($value['product_id'], $value['qty'])) {
            $offerId = isset($value['offer_id']) ? (int) $value['offer_id'] : 0;

            return [
                'product_id' => (int) $value['product_id'],
                'offer_id' => $offerId > 0 ? $offerId : null,
                'qty' => (int) $value['qty'],
            ];
        }

        // Старый формат: productId => количество.
        if (is_numeric($key) && (is_int($value) || is_numeric($value))) {
            return [
                'product_id' => (int) $key,
                'offer_id' => null,
                'qty' => (int) $value,
            ];
        }

        return null;
    }

    private function save(array $entries): void
    {
        Once::flush();

        if ($this->shared()) {
            $buyer = SessionBuyer::current();
            $draft = app(ActiveDraft::class)->current($buyer, true);
            $previous = $draft->jsonSerialize()['items'];
            $items = [];
            foreach ($previous as $line) {
                if ($line['offer_id'] === null && ! str_starts_with($line['reference'] ?? '', 'product:')) {
                    $items[] = array_intersect_key($line, array_flip(['line_id', 'offer_id', 'reference', 'quantity', 'selection_mode', 'article_confirmed', 'manufacturer_confirmed', 'comment']));
                }
            }
            foreach ($entries as $entry) {
                $old = collect($previous)->first(fn ($line) => $entry['offer_id'] !== null && $line['offer_id'] === $entry['offer_id']);
                $items[] = ['line_id' => $old['line_id'] ?? (string) Str::uuid(), 'offer_id' => $entry['offer_id'],
                    'reference' => $old['reference'] ?? 'product:'.$entry['product_id'], 'quantity' => $entry['qty'],
                    'selection_mode' => $old['selection_mode'] ?? 'vehicle_fitment', 'article_confirmed' => $old['article_confirmed'] ?? false, 'manufacturer_confirmed' => $old['manufacturer_confirmed'] ?? false];
            }
            $result = app(DraftService::class)->update($buyer, $draft->id, $this->sharedVersion ?? $draft->version, ['items' => $items]);
            $this->sharedVersion = $result->version;
        }

        if ($entries === []) {
            $this->session->forget(self::KEY);

            return;
        }

        $this->session->put(self::KEY, $entries);
    }

    private function key(int $productId, ?int $offerId): string
    {
        return $productId.':'.($offerId ?: 0);
    }

    private function sanitizeOfferId(Product $product, ?int $offerId): ?int
    {
        if ($offerId === null || $offerId <= 0) {
            return null;
        }

        $exists = $product->offers()
            ->whereKey($offerId)
            ->where('is_active', true)
            ->exists();

        return $exists ? $offerId : null;
    }

    private function clamp(int $quantity): int
    {
        return max(1, min(self::maxQuantity(), $quantity));
    }
}
