<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'type' => AssignmentType::Installation,
            'status' => AssignmentStatus::Planned,
            'scheduled_at' => now()->addDay()->setTime(10, 0),
            'duration_minutes' => 120,
        ];
    }

    public function type(AssignmentType $type): static
    {
        return $this->state([
            'type' => $type,
            'duration_minutes' => $type->defaultDuration(),
        ]);
    }

    public function status(AssignmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function at(string $time): static
    {
        return $this->state(['scheduled_at' => $time]);
    }
}
