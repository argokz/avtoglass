<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

/** Reject known contradictions without treating a name or an unverified fitment as proof. */
final class OfferVehicleConstraints
{
    public static function allows(array $offer, array $vehicle): bool
    {
        foreach ($vehicle['options'] ?? [] as $flag => $value) {
            if (is_bool($value) && array_key_exists($flag, $offer['options'] ?? [])
                && $offer['options'][$flag] !== $value) {
                return false;
            }
        }
        $ranges = $offer['vehicle_ranges'] ?? [];
        if ($ranges !== [] && isset($vehicle['car_model_id'])) {
            $ranges = array_values(array_filter($ranges, fn ($range) => $range['car_model_id'] === $vehicle['car_model_id']));
            if ($ranges === []) {
                return false;
            }
        }
        if (! isset($vehicle['year'])) {
            return true;
        }
        $year = (int) $vehicle['year'];
        $datedRanges = array_values(array_filter($ranges, fn ($range) => $range['year_from'] !== null || $range['year_to'] !== null));
        if ($datedRanges !== [] && ! array_filter($datedRanges, fn ($range) => ($range['year_from'] === null || $range['year_from'] <= $year)
            && ($range['year_to'] === null || $range['year_to'] >= $year))) {
            return false;
        }
        // Imported cards may have only a labelled range. It can exclude a wrong generation,
        // but never grants matched fitment or permission for automatic checkout.
        if (preg_match('/\b((?:19|20)\d{2})\s*[–—-]\s*((?:19|20)\d{2}|н\.?\s*в\.?)/ui', (string) ($offer['name'] ?? ''), $range)) {
            return $year >= (int) $range[1] && (! is_numeric($range[2]) || $year <= (int) $range[2]);
        }

        if (preg_match('/(?<!\d)((?:19|20)\d{2})(?!\d)/u', (string) ($offer['name'] ?? ''), $label)) {
            // A lone model/revision year is not an unbounded fitment. With no dated
            // catalogue evidence, do not infer applicability to any other year.
            return $datedRanges === [] ? $year === (int) $label[1] : $year >= (int) $label[1];
        }

        return true;
    }
}
