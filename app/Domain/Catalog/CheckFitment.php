<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Ordering\Models\OrderDraftItem;
use App\Models\CarModel;
use App\Models\Offer;
use App\Models\Product;
use Illuminate\Support\Collection;
use LogicException;

/** Deterministic rule over loaded facts. No fuzzy name, image or VIN inference. */
final class CheckFitment
{
    public function check(Offer $offer, OrderDraftItem $item, array $vehicle, ?CarModel $model, Collection $fitments): array
    {
        $review = fn (string $detail, array $missing = []) => ['status' => 'review_required', 'detail' => $detail, 'missing_options' => $missing];
        if ($item->selection_mode === 'exact_article') {
            if (! $item->article_confirmed || ! $item->manufacturer_confirmed || ! filled($offer->article)
                || $this->normalized($item->article_snapshot) !== $this->normalized($offer->article)
                || $this->normalized($item->manufacturer) !== $this->normalized($offer->supplier->name)) {
                return $review('ARTICLE_CONFIRMATION_REQUIRED');
            }

            return ['status' => 'exact_article', 'detail' => 'CUSTOMER_SELECTED_ARTICLE', 'missing_options' => []];
        }

        return $this->forVehicle($offer, $vehicle, $model, $fitments);
    }

    /**
     * Search and catalogue checks reuse the same vehicle rule.
     * Exact article confirmation stays on the draft item path above — a code hit in search is not fitment.
     */
    public function forVehicle(Offer $offer, array $vehicle, ?CarModel $model, Collection $fitments): array
    {
        $review = fn (string $detail, array $missing = []) => ['status' => 'review_required', 'detail' => $detail, 'missing_options' => $missing];
        if ($vehicle === []) {
            return $review('VEHICLE_NOT_PROVIDED');
        }
        if (! $model || $model->needs_review || ! isset($vehicle['year'])
            || (isset($vehicle['brand_id']) && $vehicle['brand_id'] !== (int) $model->car_brand_id)) {
            return $review('VEHICLE_DETAILS_REQUIRED');
        }
        $options = $vehicle['options'] ?? [];
        $required = config('ordering.fitment.required_options.'.$offer->product->glass_type->value);
        if (! is_array($required) || array_diff($required, array_keys(Product::OPTION_FLAGS))) {
            return $review('FITMENT_POLICY_UNAVAILABLE');
        }
        $missing = array_values(array_filter($required, fn ($flag) => ! isset($options[$flag])));
        if ($missing) {
            return $review('VEHICLE_OPTIONS_REQUIRED', $missing);
        }
        foreach ($options as $flag => $value) {
            if (! array_key_exists($flag, Product::OPTION_FLAGS)) {
                throw new LogicException('Unknown option reached the fitment rule.');
            }
            if ($value !== null && $value !== $offer->product->{$flag}) {
                return $review('VEHICLE_OPTIONS_MISMATCH');
            }
        }
        foreach ($fitments as $fitment) {
            if (! $fitment->is_verified || (int) $fitment->car_model_id !== $model->id || $fitment->year_from === null
                || $vehicle['year'] < $fitment->year_from || ($fitment->year_to !== null && $vehicle['year'] > $fitment->year_to)
                || filled($fitment->note)) {
                continue;
            }
            if ($fitment->car_generation_id !== null) {
                $generation = $fitment->generation;
                if (! $generation || (int) $generation->car_model_id !== $model->id
                    || ($vehicle['generation_id'] ?? null) !== (int) $generation->id
                    || ($generation->year_from !== null && $vehicle['year'] < $generation->year_from)
                    || ($generation->year_to !== null && $vehicle['year'] > $generation->year_to)) {
                    continue;
                }
            } elseif (isset($vehicle['generation_id'])) {
                // A model-wide fitment must not silently validate an unlinked generation id.
                continue;
            }
            if (filled($fitment->body_type) && $this->normalized($fitment->body_type) !== $this->normalized($vehicle['body'] ?? null)) {
                continue;
            }
            if (filled($fitment->doors) && ! in_array($vehicle['doors'] ?? null, explode('/', $fitment->doors), true)) {
                continue;
            }

            return ['status' => 'matched', 'fitment_id' => $fitment->id, 'detail' => 'VERIFIED_VEHICLE_FITMENT', 'missing_options' => []];
        }

        return $review('VERIFIED_FITMENT_REQUIRED');
    }

    private function normalized(?string $value): string
    {
        return mb_strtolower(trim($value ?? ''));
    }
}
