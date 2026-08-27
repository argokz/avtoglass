<?php

namespace Database\Factories;

use App\Enums\GlassType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Лобовое стекло '.$this->faker->unique()->words(2, true);
        $price = $this->faker->numberBetween(15_000, 90_000);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(8),
            'signature' => Str::random(40),
            'glass_type' => GlassType::Windshield,
            'is_published' => true,
            'price_min' => $price,
            'price_max' => $price,
        ];
    }

    /** Карточка, которую покупатель видеть не должен. */
    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }

    public function type(GlassType $type): static
    {
        return $this->state(['glass_type' => $type]);
    }

    public function withCamera(): static
    {
        return $this->state(['camera' => true]);
    }

    public function priced(?int $min, ?int $max = null): static
    {
        return $this->state([
            'price_min' => $min,
            'price_max' => $max ?? $min,
        ]);
    }
}
