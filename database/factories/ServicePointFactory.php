<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServicePointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Сервис на '.$this->faker->streetName(),
            'address' => $this->faker->address(),
            'bays' => 1,
            'opens_at' => '09:00',
            'closes_at' => '20:00',
            'is_active' => true,
        ];
    }

    public function bays(int $bays): static
    {
        return $this->state(['bays' => $bays]);
    }
}
