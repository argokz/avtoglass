<?php

namespace App\Livewire\Preview\Concerns;

/** Financial UX fixtures. No provider calls, bank links, refunds or fiscal documents. */
trait ShowsOrderSettlement
{
    public function settlement(?int $total = null): array
    {
        $paid = $this->demoOrder['paid'] ?? 0;
        $refunded = array_sum(array_column(array_filter($this->demoOrder['refunds'] ?? [], fn ($refund) => $refund['status'] === 'succeeded'), 'amount'));
        $net = $paid - $refunded;
        $total ??= $this->demoOrder['total'];

        return ['paid' => $paid, 'refunded' => $refunded, 'net' => $net, 'due' => $total === null ? null : max(0, $total - $net), 'refund' => $total === null ? null : max(0, $net - $total)];
    }

    private function hasPendingSettlement(): bool
    {
        return (bool) array_filter($this->demoOrder['refunds'] ?? [], fn ($refund) => $refund['status'] !== 'succeeded')
            || (bool) array_filter($this->demoOrder['paymentAttempts'] ?? [], fn ($payment) => $payment['status'] === 'pending');
    }

    private function recordAcceptedSettlement(): void
    {
        $balance = $this->settlement();
        if ($balance['refund'] > 0) {
            $id = 'refund-'.$this->demoOrder['version'];
            $this->demoOrder['refunds'][$id] = ['id' => $id, 'amount' => $balance['refund'], 'status' => 'requested', 'orderVersion' => $this->demoOrder['version'], 'attempts' => 0];
        }
        $this->updateSettlementStatus();
    }

    private function updateSettlementStatus(): void
    {
        $balance = $this->settlement();
        $this->demoOrder['payment'] = match (true) {
            $balance['refund'] > 0 => 'refund_required',
            $balance['due'] === 0 => 'paid',
            $balance['net'] > 0 => 'partial',
            default => 'unpaid',
        };
    }

    public function simulatePaidOrder(): void
    {
        $this->refreshProposalOrder();
        abort_unless(in_array($this->demoOrder['status'], ['confirmed', 'scheduled'], true)
            && ($this->demoOrder['paid'] ?? 0) === 0 && ($this->demoOrder['payment'] ?? 'unpaid') === 'unpaid'
            && ! $this->hasPendingSettlement() && $this->demoOrder['total'] !== null, 409);
        $this->demoOrder['paid'] = $this->demoOrder['total'];
        $this->demoOrder['payment'] = 'paid';
        $this->persistDemoOrder('settlement_payment_received');
    }

    public function startDemoPayment(int $orderVersion): void
    {
        $this->refreshProposalOrder();
        abort_unless($this->demoOrder['version'] === $orderVersion && ($this->proposal['status'] ?? null) === 'accepted'
            && $this->demoOrder['status'] === 'confirmed', 409);
        foreach ($this->demoOrder['paymentAttempts'] ?? [] as $attempt) {
            if ($attempt['status'] === 'pending') {
                return;
            }
        }
        $balance = $this->settlement();
        abort_unless(! $this->hasPendingSettlement() && $balance['due'] > 0, 409);
        $id = 'payment-'.$orderVersion.'-'.(count($this->demoOrder['paymentAttempts'] ?? []) + 1);
        $this->demoOrder['paymentAttempts'][$id] = ['id' => $id, 'amount' => $balance['due'], 'status' => 'pending', 'orderVersion' => $orderVersion];
        $this->demoOrder['payment'] = 'pending';
        $this->persistDemoOrder('settlement_payment_pending');
    }

    public function resolveDemoPayment(string $id, string $result): void
    {
        $this->refreshProposalOrder();
        abort_unless(in_array($result, ['succeeded', 'failed'], true), 422);
        $attempt = $this->demoOrder['paymentAttempts'][$id] ?? null;
        abort_unless($attempt, 404);
        if ($attempt['status'] === $result) {
            return;
        }
        abort_unless($attempt['status'] === 'pending', 409);
        $this->demoOrder['paymentAttempts'][$id]['status'] = $result;
        if ($result === 'succeeded') {
            $this->demoOrder['paid'] = ($this->demoOrder['paid'] ?? 0) + $attempt['amount'];
        }
        $this->updateSettlementStatus();
        $this->persistDemoOrder($result === 'succeeded' ? 'settlement_payment_received' : 'settlement_payment_failed');
    }

    public function startDemoRefund(string $id): void
    {
        $this->refreshProposalOrder();
        $refund = $this->demoOrder['refunds'][$id] ?? null;
        abort_unless($refund, 404);
        if (in_array($refund['status'], ['pending', 'succeeded'], true)) {
            return;
        }
        abort_unless($refund['amount'] <= $this->settlement()['refund'], 409);
        $this->demoOrder['refunds'][$id]['status'] = 'pending';
        $this->demoOrder['refunds'][$id]['attempts']++;
        $this->persistDemoOrder('settlement_refund_pending');
    }

    public function resolveDemoRefund(string $id, string $result): void
    {
        $this->refreshProposalOrder();
        abort_unless(in_array($result, ['succeeded', 'failed'], true), 422);
        $refund = $this->demoOrder['refunds'][$id] ?? null;
        abort_unless($refund, 404);
        if ($refund['status'] === $result) {
            return;
        }
        abort_unless($refund['status'] === 'pending', 409);
        $this->demoOrder['refunds'][$id]['status'] = $result;
        $this->updateSettlementStatus();
        $this->persistDemoOrder($result === 'succeeded' ? 'settlement_refund_succeeded' : 'settlement_refund_failed');
    }
}
