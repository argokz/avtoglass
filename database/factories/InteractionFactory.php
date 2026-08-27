<?php

namespace Database\Factories;

use App\Enums\InteractionType;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class InteractionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => InteractionType::Note,
            'body' => $this->faker->sentence(),
            'occurred_at' => now(),
        ];
    }

    public function type(InteractionType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
