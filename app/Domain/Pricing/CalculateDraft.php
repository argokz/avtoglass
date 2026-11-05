<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Catalog\CheckFitment;
use App\Domain\Fulfillment\QuoteFulfillment;
use App\Domain\Identity\BuyerContext;
use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Ordering\PaymentOptions;
use App\Domain\Ordering\SalesMode;
use App\Domain\Risk\FraudContext;
use App\Domain\Risk\FraudProvider;
use App\Domain\Risk\RiskLevel;
use App\Models\CarModel;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Product;
use App\Models\StockReservation;
use Carbon\CarbonImmutable;
use LogicException;

/** Runs inside the caller's draft transaction. All amounts come from server catalogue facts. */
final class CalculateDraft
{
    public function __construct(
        private readonly CustomerPriceCalculator $prices,
        private readonly CheckFitment $fitment,
        private readonly QuoteFulfillment $fulfillment,
    ) {}

    public function calculate(OrderDraft $draft, BuyerContext $buyer, CarbonImmutable $at): QuoteCalculation
    {
        $freshHours = config('ordering.stock_freshness_hours');
        if (! is_int($freshHours) || $freshHours <= 0) {
            throw new LogicException('Stock freshness must be a positive number of hours.');
        }
        $draft->loadMissing('items');
        $offers = Offer::query()->whereIn('id', $draft->items->pluck('offer_id')->filter())->orderBy('id')->sharedLock()
            ->with(['product.fitments' => fn ($query) => $query->orderBy('id'), 'product.fitments.generation', 'supplier'])->get()->keyBy('id');
        $held = StockReservation::query()->held()->whereIn('offer_id', $offers->keys())->selectRaw('offer_id, sum(quantity) AS quantity')->groupBy('offer_id')->pluck('quantity', 'offer_id');
        $model = isset($draft->vehicle['model_id']) ? CarModel::query()->find($draft->vehicle['model_id']) : null;
        $issues = new QuoteIssues;
        if ($draft->items->isEmpty()) {
            $issues->add('EMPTY_DRAFT', 'incomplete', field: 'items');
        }
        $name = trim($draft->contacts['name'] ?? '');
        $phone = Customer::normalizePhone($draft->contacts['phone'] ?? null);
        if (mb_strlen($name) < 2) {
            $issues->add('CONTACT_NAME_REQUIRED', 'incomplete', field: 'contacts.name');
        }
        if ($phone === null || ! preg_match('/^7[0-9]{10}$/D', $phone)) {
            $issues->add('CONTACT_PHONE_REQUIRED', 'incomplete', field: 'contacts.phone');
        }
        if ($draft->sales_mode === SalesMode::Wholesale && ! config('ordering.wholesale_auto_checkout_enabled')) {
            $issues->add('WHOLESALE_REVIEW_REQUIRED');
        } elseif ($draft->items->count() > config('ordering.limits.retail_auto_max_items')) {
            $issues->add('LARGE_ORDER_REVIEW_REQUIRED');
        }
        $goods = [];
        $knownGoods = Money::fromMinor(0);
        $unknownGoods = false;
        $facts = [];
        foreach ($draft->items as $item) {
            $offer = $offers->get($item->offer_id);
            $price = null;
            $available = null;
            $fitment = ['status' => 'review_required', 'detail' => 'OFFER_UNRESOLVED', 'missing_options' => []];
            if (! $offer || ! $offer->is_active || ! $offer->supplier?->is_active || ! $offer->product?->is_published) {
                $issues->add($offer ? 'OFFER_UNAVAILABLE' : 'UNRESOLVED_ITEM', lineId: $item->line_id);
            } else {
                $retail = $offer->price_retail !== null && $offer->currency === 'KZT' ? Money::fromDecimal($offer->price_retail) : null;
                $cost = $offer->price_wholesale !== null ? Money::fromDecimal($offer->price_wholesale) : null;
                $price = $this->prices->unitPrice($retail, $cost, $buyer->wholesaleApproved, $buyer->discountBasisPoints);
                if ($buyer->wholesaleApproved && ($cost === null || $cost->amountMinor < 0)) {
                    $price = null; // The minimum price cannot be guaranteed from missing purchase cost.
                }
                if ($price === null || $offer->price_valid_until === null || $offer->price_valid_until->lte($at)) {
                    $issues->add('PRICE_UNCONFIRMED', lineId: $item->line_id);
                }
                if (! $offer->auto_checkout_enabled) {
                    $issues->add('AUTO_CHECKOUT_DISABLED', lineId: $item->line_id);
                }
                if ($offer->product->needs_review) {
                    $issues->add('CATALOG_REVIEW_REQUIRED', lineId: $item->line_id);
                }
                $available = max(0, (int) $offer->stock_qty - (int) $held->get($offer->id, 0));
                if ($offer->stock_verified_at === null || $offer->stock_verified_at->lt($at->subHours($freshHours)) || $offer->stock_verified_at->gt($at)) {
                    $issues->add('STOCK_UNCONFIRMED', lineId: $item->line_id);
                }
                if ($available < $item->quantity) {
                    $issues->add('INSUFFICIENT_STOCK', lineId: $item->line_id);
                }
                $fitment = $this->fitment->check($offer, $item, $draft->vehicle, $model, $offer->product->fitments);
                if ($fitment['status'] === 'review_required') {
                    $issues->add('FITMENT_REVIEW_REQUIRED', lineId: $item->line_id);
                }
            }
            if ($item->quantity > config('ordering.limits.'.$draft->sales_mode->value.'_max_quantity')) {
                $issues->add('QUANTITY_LIMIT', 'incomplete', $item->line_id, 'quantity');
            }
            $lineTotal = $price?->times($item->quantity);
            $unknownGoods = $unknownGoods || $lineTotal === null;
            $knownGoods = $knownGoods->plus($lineTotal ?? Money::fromMinor(0));
            $goods[] = ['line_id' => $item->line_id, 'offer_id' => $item->offer_id, 'reference' => $item->reference,
                'article' => $offer?->article ?? $item->article_snapshot, 'name' => $offer?->product?->name ?? $item->name, 'manufacturer' => $offer?->supplier?->name ?? $item->manufacturer,
                'quantity' => $item->quantity, 'comment' => $item->comment, 'selection_mode' => $item->selection_mode, 'fitment' => $fitment,
                'unit_price' => $price?->jsonSerialize(), 'total' => $lineTotal?->jsonSerialize(), 'available_quantity' => $available];
            if ($offer) {
                $facts[] = ['offer_id' => $offer->id, 'retail' => $offer->price_retail, 'cost' => $offer->price_wholesale,
                    'currency' => $offer->currency, 'is_active' => $offer->is_active, 'auto_checkout_enabled' => $offer->auto_checkout_enabled,
                    'price_valid_until' => $offer->price_valid_until?->utc()->toIso8601String(),
                    'stock_verified_at' => $offer->stock_verified_at?->utc()->toIso8601String(),
                    'supplier' => $offer->supplier?->only(['id', 'name', 'is_active']),
                    'product' => $offer->product?->only(['id', 'name', 'is_published', 'needs_review', ...array_keys(Product::OPTION_FLAGS)]),
                    'fitments' => $offer->product?->fitments->map(fn ($row) => [
                        ...$row->only(['id', 'car_model_id', 'car_generation_id', 'year_from', 'year_to', 'body_type', 'doors', 'note', 'is_verified']),
                        'generation' => $row->generation?->only(['id', 'car_model_id', 'year_from', 'year_to']),
                    ])->all()];
            }
        }
        $fulfillment = $this->fulfillment->calculate($draft, $offers, $goods, $issues, $at);
        $goodsTotal = $unknownGoods ? null : $knownGoods;
        $servicesTotal = $fulfillment['services_total'];
        $deliveryTotal = $fulfillment['delivery_total'];
        $knownTotal = $knownGoods->plus($fulfillment['services_known_total'])->plus($deliveryTotal ?? Money::fromMinor(0));
        $total = $goodsTotal !== null && $servicesTotal !== null && $deliveryTotal !== null ? $knownTotal : null;

        $paymentMethods = PaymentOptions::available($buyer);
        $paymentMethod = $draft->payment_method ?? 'cash';
        if (! array_key_exists($paymentMethod, $paymentMethods)) {
            $issues->add('PAYMENT_METHOD_UNAVAILABLE', 'incomplete', field: 'payment_method');
        }
        $risk = app(FraudProvider::class)->assess(new FraudContext(
            Customer::phoneHash($phone), null, $total ? (float) $total->decimal() : null, $draft->sales_mode->value,
        ));
        if ($risk->level !== RiskLevel::Allow) {
            $issues->add('RISK_REVIEW_REQUIRED');
        }

        return new QuoteCalculation([
            'sales_mode' => $draft->sales_mode->value, 'locale' => $draft->locale,
            'billing' => $draft->sales_mode === SalesMode::Wholesale && $buyer->customerId !== null
                ? (Customer::query()->find($buyer->customerId)?->only(['company_name', 'bin']) ?? []) : [],
            'payment_method' => $paymentMethod, 'payment_methods' => $paymentMethods,
            'allowed_actions' => $issues->readiness() === 'incomplete' ? [] : ($issues->readiness() === 'ready' && config($draft->sales_mode === SalesMode::Wholesale ? 'ordering.wholesale_auto_checkout_enabled' : 'ordering.auto_checkout_enabled')
                ? [$draft->sales_mode === SalesMode::Wholesale ? 'confirm_wholesale' : 'confirm_retail', 'submit_for_review'] : ['submit_for_review']),
            'readiness' => $issues->readiness(), 'reasons' => $issues->codes(), 'issues' => $issues->all(),
            'items' => $goods, 'service_items' => $fulfillment['service_items'],
            'goods_total' => $goodsTotal?->jsonSerialize(), 'services_total' => $servicesTotal?->jsonSerialize(),
            'delivery_total' => $deliveryTotal?->jsonSerialize(), 'total' => $total?->jsonSerialize(), 'known_total' => $knownTotal->jsonSerialize(),
            'contacts' => ['name' => $name, 'phone' => $phone], 'vehicle' => $draft->vehicle, 'fulfillment' => $fulfillment['fulfillment'],
        ], [
            'offers' => $facts, 'fulfillment' => $fulfillment['source'],
            'model' => $model?->only(['id', 'car_brand_id', 'needs_review']),
            'buyer' => ['approved' => $buyer->wholesaleApproved, 'discount_basis_points' => $buyer->discountBasisPoints],
            'ordering_policy' => config('ordering'), 'booking_policy' => config('booking'),
        ]);
    }
}
