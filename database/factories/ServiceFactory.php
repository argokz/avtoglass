<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = 'Установка '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'price' => fake()->numberBetween(5, 25) * 1000,
            'duration_minutes' => fake()->randomElement([45, 60, 90, 120]),
            'is_active' => true,
            'is_default' => false,
            'sort' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
