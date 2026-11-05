<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Models\Order;
use App\Models\RiskBlocklistEntry;

/**
 * Локальная оценка риска: то, что можно решить своими данными.
 *
 * Правила намеренно простые и объяснимые — менеджер в очереди проверки
 * должен видеть, за что заявка помечена, а не безликий скор. Пороги —
 * в config/risk.php, подгоняются под реальный поток без выкладки кода.
 */
final class LocalFraudProvider implements FraudProvider
{
    public function assess(FraudContext $context): RiskVerdict
    {
        $reasons = [];
        $block = false;

        if ($context->phoneHash !== null && RiskBlocklistEntry::listed('phone', $context->phoneHash)) {
            $block = true;
            $reasons[] = 'PHONE_BLOCKLIST';
        }

        if ($context->ipHash !== null && RiskBlocklistEntry::listed('ip', $context->ipHash)) {
            $block = true;
            $reasons[] = 'IP_BLOCKLIST';
        }

        if ($context->phoneHash !== null) {
            $perHour = Order::query()
                ->whereHas('customer', fn ($q) => $q->where('phone_hash', $context->phoneHash))
                ->where('created_at', '>=', now()->subHour())
                ->count();
            $perDay = Order::query()
                ->whereHas('customer', fn ($q) => $q->where('phone_hash', $context->phoneHash))
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($perDay >= (int) config('risk.block_orders_per_day', 10)) {
                $block = true;
                $reasons[] = 'PHONE_VELOCITY_DAY';
            } elseif ($perHour >= (int) config('risk.review_orders_per_hour', 3)) {
                $reasons[] = 'PHONE_VELOCITY_HOUR';
            }
        }

        if ($context->ipHash !== null) {
            $ipPerDay = Order::query()
                ->where('ip_hash', $context->ipHash)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($ipPerDay >= (int) config('risk.review_ip_per_day', 5)) {
                $reasons[] = 'IP_VELOCITY_DAY';
            }
        }

        $threshold = (float) config('risk.review_amount', 500000);

        if ($context->amount !== null && $context->amount >= $threshold) {
            $reasons[] = 'LARGE_AMOUNT';
        }

        if ($block) {
            return new RiskVerdict(RiskLevel::Block, array_values(array_unique($reasons)));
        }

        return $reasons === []
            ? RiskVerdict::allow()
            : new RiskVerdict(RiskLevel::Review, array_values(array_unique($reasons)));
    }
}
