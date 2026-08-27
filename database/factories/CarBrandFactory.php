<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CarBrandFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'models_count' => 0,
        ];
    }

    /** Марка, которая должна быть видна в меню каталога. */
    public function visible(int $models = 1): static
    {
        return $this->state(['models_count' => $models]);
    }
}
