<?php

namespace Database\Factories;

use App\Models\CarBrand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CarModelFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'car_brand_id' => CarBrand::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'products_count' => 0,
        ];
    }

    public function visible(int $products = 1): static
    {
        return $this->state(['products_count' => $products]);
    }
}
