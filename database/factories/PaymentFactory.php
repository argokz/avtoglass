<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'test',
            'status' => PaymentStatus::Pending,
            'amount' => 30000,
            'currency' => 'KZT',
        ];
    }

    public function status(PaymentStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'paid_at' => $status->isPaid() ? now() : null,
        ]);
    }

    public function amount(float $amount): static
    {
        return $this->state(['amount' => $amount]);
    }
}
