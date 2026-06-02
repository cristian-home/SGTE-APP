<?php

namespace Database\Factories;

use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleType>
 */
class VehicleTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'code' => \Illuminate\Support\Str::slug($name),
            'name' => ucfirst($name),
            'allowed_license_categories' => ['C1', 'C2', 'C3'],
            'active' => true,
            'sort_order' => fake()->numberBetween(1, 99),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
