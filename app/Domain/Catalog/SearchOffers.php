<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Identity\BuyerContext;
use App\Domain\Pricing\CustomerPriceCalculator;
use App\Domain\Pricing\Money;
use App\Enums\GlassType;
use App\Models\CarModel;
use App\Models\CarModelAlias;
use App\Models\Offer;
use App\Models\StockReservation;
use App\Services\PriceList\BrandDictionary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared offer search for storefront, wholesale and assistant adapters.
 *
 * Exact normalised codes run first. Fuzzy matching is a second pass only.
 * An article hit is never treated as confirmed vehicle fitment.
 */
final class SearchOffers
{
    public const MAX_PER_PAGE = 100;

    public const DEFAULT_PER_PAGE = 24;

    private const ALLOWED = [
        'q', 'page', 'per_page', 'glass_type', 'supplier_id', 'in_stock_only',
        'car_model_id', 'brand_id', 'year', 'generation_id', 'body', 'doors', 'options',
    ];

    public function __construct(
        private readonly CustomerPriceCalculator $prices,
        private readonly CheckFitment $fitment,
    ) {}

    /**
     * @param  array<string,mixed>  $params
     * @return array{items: list<array<string,mixed>>, meta: array{page:int,per_page:int,total:int,has_more:bool}}
     */
    public function search(BuyerContext $buyer, array $params): array
    {
        if ($unknown = array_diff(array_keys($params), self::ALLOWED)) {
            throw ValidationException::withMessages([
                array_values($unknown)[0] => ['unexpected'],
            ]);
        }

        $q = $this->stringParam($params, 'q');
        $page = $this->intParam($params, 'page', 1, 1, 10_000);
        $perPage = $this->intParam($params, 'per_page', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE);
        $glassType = $this->glassTypeParam($params);
        $supplierId = $this->optionalIntParam($params, 'supplier_id', 1);
        $inStockOnly = $this->boolParam($params, 'in_stock_only', false);
        $vehicle = $this->vehicleParams($params);
        $model = $this->resolveModel($params, $q);

        $base = Offer::query()
            ->where('offers.is_active', true)
            ->whereHas('supplier', fn (Builder $builder) => $builder->where('is_active', true))
            ->whereHas('product', function (Builder $builder) use ($glassType) {
                $builder->where('is_published', true);
                if ($glassType !== null) {
                    $builder->where('glass_type', $glassType->value);
                }
            })
            ->when($supplierId !== null, fn (Builder $builder) => $builder->where('offers.supplier_id', $supplierId));

        $matchType = 'browse';
        $filtered = clone $base;
        $normalized = $q !== null ? self::normalizeCode($q) : '';

        if ($q !== null) {
            $exactIds = $this->exactCodeOfferIds($normalized);
            if ($exactIds !== []) {
                $filtered->whereIn('offers.id', $exactIds);
                $matchType = 'exact_code';
            } elseif (mb_strlen($q) <= 2) {
                $filtered->whereRaw('0 = 1');
                $matchType = 'too_short';
            } else {
                $this->applyFuzzyFilter($filtered, $q, $model);
                $matchType = 'fuzzy';
            }
        } elseif ($model !== null) {
            $this->applyVehicleFilter($filtered, $model, $vehicle);
            $matchType = 'vehicle';
        }

        if ($inStockOnly) {
            $heldSub = StockReservation::query()->held()
                ->selectRaw('offer_id, sum(quantity) as quantity')
                ->groupBy('offer_id');
            $filtered->leftJoinSub($heldSub, 'held_stock', 'held_stock.offer_id', '=', 'offers.id')
                ->whereRaw('(offers.stock_qty - coalesce(held_stock.quantity, 0)) > 0')
                ->select('offers.*');
        }

        $total = (clone $filtered)->count('offers.id');
        $offers = $filtered
            ->with([
                'product.fitments' => fn ($builder) => $builder->orderBy('id'),
                'product.fitments.generation',
                'supplier',
            ])
            ->orderBy('offers.id')
            ->forPage($page, $perPage)
            ->get();

        $held = $this->heldByOffer($offers->pluck('id')->all());
        $items = [];
        foreach ($offers as $offer) {
            $available = max(0, (int) $offer->stock_qty - (int) $held->get($offer->id, 0));
            $retail = $offer->price_retail !== null && $offer->currency === 'KZT'
                ? Money::fromDecimal((string) $offer->price_retail)
                : null;
            $cost = $offer->price_wholesale !== null
                ? Money::fromDecimal((string) $offer->price_wholesale)
                : null;
            $price = $this->prices->unitPrice($retail, $cost, $buyer->wholesaleApproved, $buyer->discountBasisPoints);
            if ($buyer->wholesaleApproved && ($cost === null || $cost->amountMinor < 0)) {
                $price = null;
            }

            $fitment = $this->fitment->forVehicle(
                $offer,
                $vehicle,
                $model,
                $offer->product?->fitments ?? collect(),
            );

            $items[] = [
                'offer_id' => $offer->id,
                'product_id' => $offer->product_id,
                'name' => $offer->product?->name,
                'article' => $offer->article,
                'eurocode' => $offer->product?->eurocode,
                'oem_code' => $offer->product?->oem_code,
                'glass_type' => $offer->product?->glass_type?->value,
                'manufacturer' => [
                    'id' => $offer->supplier?->id,
                    'name' => $offer->supplier?->name,
                ],
                'unit_price' => $price?->jsonSerialize(),
                'available_qty' => $available,
                'match' => [
                    'type' => $matchType,
                    'field' => match ($matchType) {
                        'exact_code' => $this->matchFieldForOffer($normalized, $offer),
                        'fuzzy' => $this->fuzzyFieldForOffer((string) $q, $offer, $model),
                        'vehicle' => 'vehicle',
                        default => null,
                    },
                ],
                'fitment' => [
                    'status' => $fitment['status'] === 'matched' ? 'matched' : 'review_required',
                    'detail' => $fitment['detail'],
                    'missing_options' => $fitment['missing_options'],
                    'fitment_id' => $fitment['fitment_id'] ?? null,
                ],
                'auto_checkout_enabled' => (bool) $offer->auto_checkout_enabled,
            ];
        }

        usort($items, function (array $left, array $right) {
            $rank = fn (array $row) => [
                $row['match']['type'] === 'exact_code' ? 0 : 1,
                $row['fitment']['status'] === 'matched' ? 0 : 1,
                $row['available_qty'] > 0 ? 0 : 1,
                $row['unit_price'] === null ? 1 : 0,
                $row['offer_id'],
            ];

            return $rank($left) <=> $rank($right);
        });

        return [
            'items' => array_values($items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    public static function normalizeCode(string $value): string
    {
        return mb_strtoupper(preg_replace('/[\s.\-]+/u', '', $value) ?? '');
    }

    /** @return list<int> */
    private function exactCodeOfferIds(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $article = $this->normalizedSql('offers.article');
        $eurocode = $this->normalizedSql('products.eurocode');
        $oem = $this->normalizedSql('products.oem_code');
        $internal = $this->normalizedSql('products.internal_code');

        return Offer::query()
            ->join('products', 'products.id', '=', 'offers.product_id')
            ->where('offers.is_active', true)
            ->where('products.is_published', true)
            ->where(function (Builder $builder) use ($normalized, $article, $eurocode, $oem, $internal) {
                $builder->whereRaw("{$article} = ?", [$normalized])
                    ->orWhereRaw("{$eurocode} = ?", [$normalized])
                    ->orWhereRaw("{$oem} = ?", [$normalized])
                    ->orWhereRaw("{$internal} = ?", [$normalized]);
            })
            ->orderBy('offers.id')
            ->limit(500)
            ->pluck('offers.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function normalizedSql(string $column): string
    {
        return "upper(regexp_replace(coalesce({$column}, ''), '[\\s.\\-]+', '', 'g'))";
    }

    private function applyFuzzyFilter(Builder $query, string $term, ?CarModel $model): void
    {
        $like = '%'.$term.'%';
        $query->where(function (Builder $builder) use ($like, $term, $model) {
            $builder->where('offers.article', 'ilike', $like)
                ->orWhereHas('product', function (Builder $product) use ($like, $term) {
                    $product->where('name', 'ilike', $like)
                        ->orWhere('eurocode', 'ilike', $like)
                        ->orWhere('oem_code', 'ilike', $like)
                        ->orWhere('internal_code', 'ilike', $like);
                    if (DB::connection()->getDriverName() === 'pgsql' && mb_strlen($term) >= 3) {
                        $product->orWhereRaw('similarity(name, ?) > 0.2', [$term]);
                    }
                });
            if ($model !== null) {
                $builder->orWhereHas('product.fitments', fn (Builder $fit) => $fit
                    ->where('car_model_id', $model->id)
                    ->where('is_verified', true));
            }
        });
    }

    /** @param  array<string,mixed>  $vehicle */
    private function applyVehicleFilter(Builder $query, CarModel $model, array $vehicle): void
    {
        $query->whereHas('product.fitments', function (Builder $fit) use ($model, $vehicle) {
            $fit->where('car_model_id', $model->id)->where('is_verified', true);
            if (isset($vehicle['year'])) {
                $year = $vehicle['year'];
                $fit->where(fn (Builder $yearQ) => $yearQ->whereNull('year_from')->orWhere('year_from', '<=', $year))
                    ->where(fn (Builder $yearQ) => $yearQ->whereNull('year_to')->orWhere('year_to', '>=', $year));
            }
        });
    }

    /** @param  array<string,mixed>  $params */
    private function resolveModel(array $params, ?string $query): ?CarModel
    {
        $modelId = $this->optionalIntParam($params, 'car_model_id', 1);
        if ($modelId !== null) {
            return CarModel::query()->find($modelId);
        }

        if ($query === null || mb_strlen($query) < 3) {
            return null;
        }

        $alias = CarModelAlias::query()
            ->whereRaw('lower(alias) = ?', [mb_strtolower($query)])
            ->first();
        if ($alias !== null) {
            return CarModel::query()
                ->where('name', $alias->model_name)
                ->when($alias->car_brand_id, fn (Builder $builder) => $builder->where('car_brand_id', $alias->car_brand_id))
                ->first();
        }

        $brand = BrandDictionary::canonical($query);
        if ($brand !== null) {
            $byBrand = CarModel::query()
                ->whereHas('brand', fn (Builder $builder) => $builder->whereRaw('lower(name) = ?', [mb_strtolower($brand)]))
                ->orderBy('name')
                ->first();
            if ($byBrand !== null) {
                return $byBrand;
            }
        }

        return CarModel::query()
            ->where('name', 'ilike', '%'.$query.'%')
            ->orderBy('name')
            ->first();
    }

    /**
     * @param  list<int>  $offerIds
     * @return Collection<int|string, int|string>
     */
    private function heldByOffer(array $offerIds): Collection
    {
        if ($offerIds === []) {
            return collect();
        }

        return StockReservation::query()->held()
            ->whereIn('offer_id', $offerIds)
            ->selectRaw('offer_id, sum(quantity) as quantity')
            ->groupBy('offer_id')
            ->pluck('quantity', 'offer_id');
    }

    private function matchFieldForOffer(string $normalized, Offer $offer): string
    {
        if (self::normalizeCode((string) $offer->article) === $normalized) {
            return 'article';
        }
        if (self::normalizeCode((string) $offer->product?->eurocode) === $normalized) {
            return 'eurocode';
        }
        if (self::normalizeCode((string) $offer->product?->oem_code) === $normalized) {
            return 'oem_code';
        }
        if (self::normalizeCode((string) $offer->product?->internal_code) === $normalized) {
            return 'internal_code';
        }

        return 'article';
    }

    private function fuzzyFieldForOffer(string $term, Offer $offer, ?CarModel $model): string
    {
        $needle = mb_strtolower($term);
        foreach ([
            'article' => (string) $offer->article,
            'eurocode' => (string) $offer->product?->eurocode,
            'oem_code' => (string) $offer->product?->oem_code,
            'internal_code' => (string) $offer->product?->internal_code,
            'name' => (string) $offer->product?->name,
        ] as $field => $value) {
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                return $field;
            }
        }

        return $model !== null ? 'vehicle' : 'name';
    }

    /** @param  array<string,mixed>  $params */
    private function stringParam(array $params, string $key): ?string
    {
        if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return null;
        }
        if (! is_string($params[$key])) {
            throw ValidationException::withMessages([$key => ['invalid']]);
        }
        $value = trim($params[$key]);
        if (mb_strlen($value) > 100) {
            throw ValidationException::withMessages([$key => ['invalid']]);
        }

        return $value === '' ? null : $value;
    }

    /** @param  array<string,mixed>  $params */
    private function intParam(array $params, string $key, int $default, int $min, int $max): int
    {
        if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return $default;
        }
        if (is_int($params[$key])) {
            $value = $params[$key];
        } elseif (is_string($params[$key]) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $params[$key])) {
            $value = (int) $params[$key];
        } else {
            throw ValidationException::withMessages([$key => ['invalid']]);
        }
        if ($value < $min || $value > $max) {
            throw ValidationException::withMessages([$key => ['invalid']]);
        }

        return $value;
    }

    /** @param  array<string,mixed>  $params */
    private function optionalIntParam(array $params, string $key, int $min): ?int
    {
        if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return null;
        }

        return $this->intParam($params, $key, $min, $min, 2_147_483_647);
    }

    /** @param  array<string,mixed>  $params */
    private function boolParam(array $params, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return $default;
        }
        if (is_bool($params[$key])) {
            return $params[$key];
        }
        if (is_string($params[$key]) && in_array($params[$key], ['0', '1', 'true', 'false'], true)) {
            return in_array($params[$key], ['1', 'true'], true);
        }

        throw ValidationException::withMessages([$key => ['invalid']]);
    }

    /** @param  array<string,mixed>  $params */
    private function glassTypeParam(array $params): ?GlassType
    {
        if (! array_key_exists('glass_type', $params) || $params['glass_type'] === null || $params['glass_type'] === '') {
            return null;
        }
        if (! is_string($params['glass_type'])) {
            throw ValidationException::withMessages(['glass_type' => ['invalid']]);
        }

        return GlassType::tryFrom($params['glass_type'])
            ?? throw ValidationException::withMessages(['glass_type' => ['invalid']]);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function vehicleParams(array $params): array
    {
        $vehicle = [];
        if (($year = $this->optionalIntParam($params, 'year', 1980)) !== null) {
            if ($year > ((int) date('Y')) + 1) {
                throw ValidationException::withMessages(['year' => ['invalid']]);
            }
            $vehicle['year'] = $year;
        }
        if (($generationId = $this->optionalIntParam($params, 'generation_id', 1)) !== null) {
            $vehicle['generation_id'] = $generationId;
        }
        if (($brandId = $this->optionalIntParam($params, 'brand_id', 1)) !== null) {
            $vehicle['brand_id'] = $brandId;
        }
        foreach (['body', 'doors'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null && $params[$field] !== '') {
                if (! is_string($params[$field])) {
                    throw ValidationException::withMessages([$field => ['invalid']]);
                }
                $vehicle[$field] = $params[$field];
            }
        }
        if (array_key_exists('options', $params) && $params['options'] !== null && $params['options'] !== '') {
            if (! is_array($params['options'])) {
                throw ValidationException::withMessages(['options' => ['invalid']]);
            }
            $vehicle['options'] = $params['options'];
        }

        return $vehicle;
    }
}
