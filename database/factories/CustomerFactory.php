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
}
