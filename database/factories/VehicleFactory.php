<?php

namespace Database\Factories;

use App\Enums\VehicleStatus;
use App\Models\Municipality;
use App\Models\ThirdParty;
use App\Models\VehicleType;
use App\Support\Tz;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $isThirdParty = fake()->boolean(20);

        return [
            'internal_code' => fake()->unique()->numerify('V-###'),
            'plate' => strtoupper(fake()->unique()->bothify('???###')),
            'mobile_number' => fake()->numerify('3#########'),
            'brand' => fake()->randomElement(['Chevrolet', 'Toyota', 'Hyundai', 'Kia', 'Nissan', 'Mercedes-Benz']),
            'line' => fake()->randomElement(['NKR', 'NPR', 'Coaster', 'County', 'Pregio', 'Sprinter', 'Dyna']),
            'model_year' => fake()->numberBetween(2015, 2026),
            'vehicle_type_id' => VehicleType::query()->inRandomOrder()->value('id')
                ?? VehicleType::factory(),
            'engine_number' => fake()->bothify('??#####??##'),
            'chassis_number' => fake()->bothify('?????????????????'),
            'capacity' => fake()->numberBetween(4, 40),
            'municipality_id' => Municipality::inRandomOrder()->first()?->id ?? Municipality::factory(),
            'is_third_party' => $isThirdParty,
            'third_party_id' => $isThirdParty ? ThirdParty::factory() : null,
            'timezone' => Tz::operation(),
            'soat_due_date' => fake()->dateTimeBetween('+1 month', '+1 year'),
            'rtm_due_date' => fake()->dateTimeBetween('+1 month', '+1 year'),
            'operation_card_due_date' => fake()->dateTimeBetween('+1 month', '+2 years'),
            'status' => fake()->randomElement([VehicleStatus::Active, VehicleStatus::Active, VehicleStatus::Active, VehicleStatus::Maintenance, VehicleStatus::Retired]),
        ];
    }
}
