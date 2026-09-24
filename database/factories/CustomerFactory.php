<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone' => '77'.$this->faker->unique()->numerify('#########'),
            'email' => $this->faker->optional()->safeEmail(),
        ];
    }

    public function wholesale(int $discount = 10): static
    {
        return $this->state([
            'wholesale_status' => \App\Enums\WholesaleStatus::Wholesale,
            'company_name' => 'Сервис Тест',
            'bin' => '123456789012',
            'wholesale_discount_percent' => $discount,
            'wholesale_approved_at' => now(),
        ]);
    }

    public function pendingWholesale(): static
    {
        return $this->state([
            'wholesale_status' => \App\Enums\WholesaleStatus::Pending,
            'company_name' => 'Сервис Тест',
            'bin' => '123456789012',
            'wholesale_requested_at' => now(),
        ]);
    }
}
