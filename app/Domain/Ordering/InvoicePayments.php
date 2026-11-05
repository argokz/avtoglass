<?php

declare(strict_types=1);

namespace App\Domain\Ordering;

use App\Domain\Pricing\Money;
use App\Domain\Shared\PurchaseException;
use App\Enums\PaymentStatus;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class InvoicePayments
{
    public function confirm(Order $order, User $staff, string $reference): Payment
    {
        if (! $staff->is_active || ! $staff->role->canWorkWithOrders() || trim($reference) === '' || mb_strlen($reference) > 100) {
            throw new PurchaseException('FORBIDDEN', 403);
        }

        return DB::transaction(function () use ($order, $staff, $reference) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $key = 'invoice:'.hash('sha256', mb_strtoupper(trim($reference)));
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
            if ($previous = Payment::query()->where('provider', 'invoice')->where('external_id', $key)->first()) {
                if ($previous->order_id !== $order->id) {
                    throw new PurchaseException('PAYMENT_REFERENCE_ALREADY_USED', 409);
                }

                return $previous;
            }
            if ($order->payment_method?->value !== 'invoice' || ! in_array($order->purchase_state, ['confirmed', 'reservation_expired', 'paid_review'], true) || $order->isPaid() || $order->total_amount === null) {
                throw new PurchaseException('PAYMENT_NOT_ALLOWED', 409);
            }
            $payment = Payment::query()->create(['order_id' => $order->id, 'provider' => 'invoice', 'external_id' => $key,
                'status' => PaymentStatus::Succeeded, 'amount' => Money::fromDecimal($order->total_amount)->decimal(), 'currency' => 'KZT', 'received_by' => $staff->id, 'paid_at' => now()]);
            app(PurchaseLifecycle::class)->paid($order);
            Interaction::system($order, 'Подтверждён банковский перевод по счёту');

            return $payment;
        });
    }
}
