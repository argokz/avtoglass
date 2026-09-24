<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Ordering\Models\OrderDraft;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\QuoteIssues;
use App\Models\Assignment;
use App\Models\Service;
use App\Models\ServicePoint;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Prices and eligibility only. This operation never books a resource. */
final class QuoteFulfillment
{
    public function calculate(OrderDraft $draft, Collection $offers, array $goods, QuoteIssues $issues, CarbonImmutable $at): array
    {
        $selection = $draft->fulfillment;
        $type = $selection['type'] ?? 'pickup';
        $city = trim($selection['city'] ?? '');
        $servicesKnown = Money::fromMinor(0);
        $servicesTotal = $servicesKnown;
        $deliveryTotal = in_array($type, ['pickup', 'installation'], true) ? Money::fromMinor(0) : null;
        $serviceItems = [];
        $duration = 0;
        $source = [];
        $point = null;
        if ($city === '') {
            $issues->add('CITY_REQUIRED', 'incomplete', field: 'fulfillment.city');
        }
        if (in_array($type, ['delivery', 'visit'], true) && trim($selection['address'] ?? '') === '') {
            $issues->add('ADDRESS_REQUIRED', 'incomplete', field: 'fulfillment.address');
        }
        if (in_array($type, ['pickup', 'installation'], true)) {
            $point = isset($selection['service_point_id']) ? ServicePoint::query()->find($selection['service_point_id']) : null;
            if (! $point) {
                $issues->add('SERVICE_POINT_REQUIRED', 'incomplete', field: 'fulfillment.service_point_id');
            } elseif (! $point->is_active || $city === '' || self::cityKey($point->city ?? '') !== self::cityKey($city)) {
                $issues->add('DELIVERY_REVIEW_REQUIRED', field: 'fulfillment.service_point_id');
            }
            $permissions = $point ? DB::table('offer_fulfillment_points')->where('service_point_id', $point->id)
                ->whereIn('offer_id', $offers->keys())->orderBy('offer_id')->get()->keyBy('offer_id') : collect();
            foreach ($draft->items as $item) {
                $permission = $permissions->get($item->offer_id);
                $enabled = $type === 'pickup' ? ($permission?->pickup_enabled ?? false) : ($permission?->installation_enabled ?? false);
                if (! $enabled) {
                    $issues->add('DELIVERY_REVIEW_REQUIRED', lineId: $item->line_id);
                }
            }
            $source['point'] = $point?->only(['id', 'name', 'address', 'city', 'is_active', 'timezone', 'bays', 'opens_at', 'closes_at']);
            $source['permissions'] = $permissions->map(fn ($row) => (array) $row)->values()->all();
        }
        if ($type === 'delivery') {
            $zone = config('ordering.fulfillment.delivery_zones.'.self::cityKey($city));
            $source['delivery_zone'] = $zone;
            $fee = is_array($zone) ? ($zone['fee_minor'] ?? null) : null;
            $suppliers = is_array($zone) ? ($zone['supplier_ids'] ?? []) : [];
            $allowed = is_array($suppliers) && $offers->isNotEmpty() && $offers->count() === $draft->items->count()
                && $offers->every(fn ($offer) => in_array((int) $offer->supplier_id, $suppliers, true));
            if ($allowed && is_int($fee) && $fee >= 0 && $fee <= Money::MAX_MINOR) {
                $deliveryTotal = Money::fromMinor($fee);
            } else {
                $issues->add('DELIVERY_REVIEW_REQUIRED');
            }
        }
        if (in_array($type, ['installation', 'visit'], true)) {
            $services = Service::query()->active()->where(fn ($query) => $query->where('is_default', true)->orWhere('slug', 'vyezd-mastera'))->orderBy('id')->get();
            $source['services'] = $services->map(fn ($service) => [
                'id' => $service->id, 'name' => $service->name, 'slug' => $service->slug, 'glass_type' => $service->glass_type?->value,
                'price' => $service->price, 'duration_minutes' => (int) $service->duration_minutes,
                'is_default' => $service->is_default, 'sort' => (int) $service->sort,
            ])->all();
            $unknownService = false;
            foreach ($goods as $line) {
                $offer = $offers->get($line['offer_id']);
                if (! $offer || ! $offer->product->glass_type->isGlass()) {
                    if (! $offer) {
                        $unknownService = true;
                    }

                    continue;
                }
                if ($line['quantity'] > 1) {
                    $issues->add('MULTIPLE_VEHICLES_REVIEW_REQUIRED', lineId: $line['line_id']);
                }
                if ($line['fitment']['status'] !== 'matched') {
                    $issues->add('FITMENT_REVIEW_REQUIRED', lineId: $line['line_id']);
                }
                $candidates = $services->filter(fn ($service) => $service->is_default && $service->slug !== 'vyezd-mastera'
                    && ($service->glass_type === $offer->product->glass_type || $service->glass_type === null));
                $service = $candidates->sortBy(fn ($candidate) => [
                    $candidate->glass_type === null ? 1 : 0, (int) $candidate->sort,
                    $candidate->price === null ? PHP_INT_MAX : Money::fromDecimal($candidate->price)->amountMinor, $candidate->id,
                ])->first();
                $price = $service?->price !== null ? Money::fromDecimal($service->price) : null;
                if ($price !== null && $price->amountMinor < 0) {
                    $price = null;
                }
                if (! $service || $price === null || $service->duration_minutes <= 0) {
                    $issues->add('SERVICE_REVIEW_REQUIRED', lineId: $line['line_id']);
                    $unknownService = true;
                }
                $lineTotal = $price?->times($line['quantity']);
                $servicesKnown = $servicesKnown->plus($lineTotal ?? Money::fromMinor(0));
                $duration += max(0, (int) ($service?->duration_minutes ?? 0)) * $line['quantity'];
                $serviceItems[] = ['service_id' => $service?->id, 'for_line_id' => $line['line_id'],
                    'name' => $service?->name, 'quantity' => $line['quantity'], 'unit_price' => $price?->jsonSerialize(), 'total' => $lineTotal?->jsonSerialize()];
            }
            if ($serviceItems === []) {
                $unknownService = true;
                $issues->add('SERVICE_REVIEW_REQUIRED');
            }
            $servicesTotal = $unknownService ? null : $servicesKnown;
            $duration += (int) config('booking.buffer_minutes');
            if ($type === 'visit') {
                $fee = $services->firstWhere('slug', 'vyezd-mastera');
                $deliveryTotal = $fee?->price !== null ? Money::fromDecimal($fee->price) : null;
                if ($deliveryTotal?->amountMinor < 0) {
                    $deliveryTotal = null;
                }
                // Existing data has no visit resource/zone assignment. A price alone cannot promise a visit.
                $issues->add('VISIT_RESOURCE_REVIEW_REQUIRED');
                if ($deliveryTotal === null) {
                    $issues->add('DELIVERY_REVIEW_REQUIRED');
                }
            }
            $source['slot'] = $this->slot($selection, $point, $duration, $issues, $at);
        }

        foreach (['slot_start', 'slot_end'] as $field) {
            if (isset($selection[$field])) {
                $selection[$field] = CarbonImmutable::parse($selection[$field])->utc()->toIso8601String();
            }
        }

        return [
            'services_total' => $servicesTotal, 'services_known_total' => $servicesKnown, 'delivery_total' => $deliveryTotal,
            'service_items' => $serviceItems, 'source' => $source,
            'fulfillment' => [...$selection, 'point' => $point ? $point->only(['id', 'name', 'address', 'city', 'timezone']) : null,
                'duration_minutes' => in_array($type, ['installation', 'visit'], true) ? $duration : null],
        ];
    }

    private function slot(array $selection, ?ServicePoint $point, int $duration, QuoteIssues $issues, CarbonImmutable $at): array
    {
        if (! isset($selection['slot_start'], $selection['slot_end'])) {
            $issues->add('SLOT_REQUIRED', 'incomplete', field: 'fulfillment.slot_start');

            return [];
        }
        $start = CarbonImmutable::parse($selection['slot_start']);
        $end = CarbonImmutable::parse($selection['slot_end']);
        if ($end->lte($start) || ! $end->equalTo($start->addMinutes($duration))) {
            $issues->add('SLOT_DURATION_CHANGED', field: 'fulfillment.slot_end');
        }
        if (! $point || ! in_array($point->timezone, DateTimeZone::listIdentifiers(), true)) {
            $issues->add('SERVICE_REVIEW_REQUIRED');

            return [];
        }
        $localStart = $start->setTimezone($point->timezone);
        $localEnd = $end->setTimezone($point->timezone);
        $step = (int) config('booking.step_minutes');
        $opens = $localStart->setTimeFromTimeString($point->opens_at);
        $closes = $localStart->setTimeFromTimeString($point->closes_at);
        if ($duration <= 0 || $step <= 0 || $localStart->second !== 0 || ($localStart->hour * 60 + $localStart->minute) % max(1, $step) !== 0
            || $start->lt($at->addHours((int) config('booking.lead_hours')))
            || $start->gt($at->setTimezone($point->timezone)->addDays((int) config('booking.horizon_days'))->endOfDay())
            || $localStart->lt($opens) || $localEnd->gt($closes) || ! $localStart->isSameDay($localEnd)) {
            $issues->add('SLOT_UNAVAILABLE', field: 'fulfillment.slot_start');
        }
        // Legacy assignments are timestamp-without-timezone in the application timezone.
        $legacyZone = config('app.timezone');
        $assignments = Assignment::query()->active()->where('service_point_id', $point->id)
            ->where('scheduled_at', '<', $end->setTimezone($legacyZone)->format('Y-m-d H:i:s'))
            ->whereRaw("scheduled_at + duration_minutes * interval '1 minute' > ?", [$start->setTimezone($legacyZone)->format('Y-m-d H:i:s')])
            ->orderBy('id')->get();
        $events = [];
        foreach ($assignments as $assignment) {
            $events[] = [max($start->timestamp, $assignment->scheduled_at->timestamp), 1];
            $events[] = [min($end->timestamp, $assignment->endsAt()->timestamp), -1];
        }
        sort($events);
        $busy = $peak = 0;
        foreach ($events as [, $delta]) {
            $busy += $delta;
            $peak = max($peak, $busy);
        }
        if ($peak >= (int) $point->bays) {
            $issues->add('SLOT_UNAVAILABLE', field: 'fulfillment.slot_start');
        }

        return $assignments->map(fn ($assignment) => ['id' => $assignment->id, 'start' => $assignment->scheduled_at->utc()->toIso8601String(),
            'duration' => (int) $assignment->duration_minutes, 'status' => $assignment->status->value])->all();
    }

    public static function cityKey(string $city): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $city)));
    }
}
