<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Models\Interaction;
use App\Models\Order;
use App\Models\Task;

/**
 * Применяет вердикт антифрода к свежей заявке.
 *
 * allow  — ничего не меняется;
 * review — заявка живёт обычной жизнью, но помечается и получает задачу
 *          на проверку: менеджер решает до звонка клиенту;
 * block  — заявка сохраняется (улики и след попытки), но смена о ней
 *          не оповещается и задача на перезвон не ставится: мошеннику
 *          не перезванивают.
 */
final class AssessOrderRisk
{
    public function __construct(private readonly FraudProvider $provider) {}

    public function apply(Order $order, ?string $ip): RiskLevel
    {
        $order->loadMissing('customer');

        $ipHash = $ip !== null && $ip !== ''
            ? hash_hmac('sha256', $ip, (string) config('app.key'))
            : null;

        $verdict = $this->provider->assess(new FraudContext(
            phoneHash: $order->customer?->phone_hash,
            ipHash: $ipHash,
            amount: $order->total_amount !== null ? (float) $order->total_amount : null,
            source: (string) $order->source?->value,
        ));

        $order->forceFill([
            'risk_status' => $verdict->level->value,
            'risk_reasons' => $verdict->reasons === [] ? null : $verdict->reasons,
            'ip_hash' => $ipHash,
        ])->save();

        if ($verdict->level === RiskLevel::Allow) {
            return $verdict->level;
        }

        Interaction::system($order, 'Антифрод: '.$verdict->level->label()
            .($verdict->reasons !== [] ? ' ('.implode(', ', $verdict->reasons).')' : ''));

        if ($verdict->level === RiskLevel::Review) {
            Task::create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'title' => "Проверить заявку {$order->number} на мошенничество",
                'description' => 'Антифрод пометил заявку: '.implode(', ', $verdict->reasons)
                    .'. Проверить до звонка клиенту.',
                'due_at' => now()->addMinutes(10),
            ]);
        }

        return $verdict->level;
    }
}
