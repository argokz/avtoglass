<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfferFactory extends Factory
{
    public function definition(): array
    {
        $wholesale = $this->faker->numberBetween(10_000, 70_000);

        return [
            'product_id' => Product::factory(),
            'supplier_id' => Supplier::factory(),
            'raw_name' => $this->faker->sentence(),
            'price_wholesale' => $wholesale,
            'price_retail' => round($wholesale * 1.35, -1),
            'currency' => 'KZT',
            'stock_qty' => 0,
            'warranty_months' => 12,
            'is_active' => true,
        ];
    }

    public function price(int $retail): static
    {
        return $this->state([
            'price_wholesale' => (int) round($retail / 1.35),
            'price_retail' => $retail,
        ]);
    }
}
