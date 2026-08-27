<?php

namespace Database\Factories;

use App\Models\CarModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class FitmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'car_model_id' => CarModel::factory(),
            'year_from' => 2010,
            'year_to' => null,
        ];
    }
}
