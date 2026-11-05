<?php

declare(strict_types=1);

namespace App\Ai\Runtime;

use App\Ai\JourneyTools;
use App\Models\Product;

/** A small, server-owned record of facts the model actually saw during a turn. */
final class ToolResultSnapshot
{
    public static function make(string $name, array $args, array $result): ?array
    {
        if (in_array($name, array_column(JourneyTools::declarations(), 'name'), true)) {
            $safe = array_intersect_key($result, array_flip(['code', 'order', 'orders', 'cancel', 'quote_id', 'readiness', 'reasons', 'total', 'url', 'items', 'vehicle']));
            if (isset($result['draft'])) {
                $safe['draft'] = array_intersect_key($result['draft'], array_flip(['id', 'version', 'status', 'sales_mode']));
                $safe['draft']['line_count'] = count($result['draft']['items'] ?? []);
            }

            return ['args' => [], 'result' => $safe];
        }
        if (! in_array($name, ['search_products', 'prepare_order'], true)) {
            return null;
        }

        if (isset($result['code'])) {
            return ['args' => [], 'result' => ['code' => self::short($result['code'], 40)]];
        }

        if ($name === 'prepare_order') {
            // The encrypted checkout owns contacts and the full quote. Do not copy PII into chat context.
            $checkout = $result['checkout'] ?? [];

            return ['args' => [], 'result' => [
                'ok' => (bool) ($result['ok'] ?? false),
                'checkout_id' => self::short($checkout['id'] ?? null, 36),
                'quote_id' => self::short($checkout['quote_id'] ?? null, 36),
                'status' => self::short($checkout['status'] ?? null, 24),
                'note' => 'Расчёт подготовлен; заказ появляется только после подтверждения покупателем.',
            ]];
        }

        $items = [];
        foreach (array_slice($result['items'] ?? [], 0, 8) as $row) {
            if (! is_array($row) || ! is_int($row['offer_id'] ?? null)) {
                continue;
            }
            $items[] = [
                'offer_id' => $row['offer_id'],
                'product_id' => is_int($row['product_id'] ?? null) ? $row['product_id'] : null,
                'name' => self::short($row['name'] ?? null),
                'article' => self::short($row['article'] ?? null),
                'eurocode' => self::short($row['eurocode'] ?? null),
                'glass_type' => self::short($row['glass_type'] ?? null, 40),
                'options' => array_intersect_key($row['options'] ?? [], Product::OPTION_FLAGS),
                'vehicle_ranges' => array_slice($row['vehicle_ranges'] ?? [], 0, 20),
                'manufacturer' => self::short(is_array($row['manufacturer'] ?? null) ? ($row['manufacturer']['name'] ?? null) : ($row['manufacturer'] ?? null)),
                'unit_price' => [
                    'amount_minor' => is_int($row['unit_price']['amount_minor'] ?? null) ? $row['unit_price']['amount_minor'] : null,
                    'currency' => self::short($row['unit_price']['currency'] ?? null, 8),
                ],
                'available_qty' => is_int($row['available_qty'] ?? null) ? $row['available_qty'] : null,
                'availability' => [
                    'state' => self::short($row['availability']['state'] ?? null, 16),
                    'days' => is_int($row['availability']['days'] ?? null) ? $row['availability']['days'] : null,
                    'ready_by' => self::short($row['availability']['ready_by'] ?? null, 10),
                ],
                'fitment' => [
                    'status' => self::short($row['fitment']['status'] ?? null, 32),
                    'detail' => self::short($row['fitment']['detail'] ?? null, 160),
                    'missing_options' => array_map(
                        fn (string $option) => self::short($option, 40),
                        array_values(array_slice(array_filter($row['fitment']['missing_options'] ?? [], 'is_string'), 0, 12)),
                    ),
                ],
            ];
        }

        $vehicle = array_intersect_key($result['vehicle'] ?? [], array_flip(['car_model_id', 'brand_id', 'year', 'generation_id']));
        $vehicle = array_filter($vehicle, 'is_int');

        return [
            'args' => self::searchArgs($args),
            'result' => [
                'items' => $items,
                'meta' => array_intersect_key($result['meta'] ?? [], array_flip(['total', 'has_more'])),
                'vehicle' => $vehicle,
            ],
        ];
    }

    /** Keep the search itself, never contacts or free-form addresses. */
    private static function searchArgs(array $args): array
    {
        $safe = [];
        if (is_string($args['query'] ?? null) && $args['query'] !== '') {
            $safe['query'] = self::short($args['query'], 100);
        }
        if (is_string($args['glass_type'] ?? null) && $args['glass_type'] !== '') {
            $safe['glass_type'] = self::short($args['glass_type'], 40);
        }
        foreach (['year', 'car_model_id', 'brand_id', 'generation_id'] as $field) {
            if (is_int($args[$field] ?? null)) {
                $safe[$field] = $args[$field];
            }
        }
        $options = [];
        foreach ($args['options'] ?? [] as $flag => $value) {
            if (is_string($flag) && array_key_exists($flag, Product::OPTION_FLAGS) && is_bool($value)) {
                $options[$flag] = $value;
            }
        }
        if ($options !== []) {
            $safe['options'] = $options;
        }

        return $safe;
    }

    private static function short(mixed $value, int $limit = 120): ?string
    {
        return is_string($value) ? mb_substr($value, 0, $limit) : null;
    }
}
