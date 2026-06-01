<?php

use App\Enums\VehicleType;
use App\Models\Vehicle;

it('persists a vehicle for every VehicleType enum case', function (VehicleType $type) {
    $vehicle = Vehicle::factory()->create(['type' => $type->value]);

    expect($vehicle->fresh()->type)->toBe($type);
})->with(VehicleType::cases());
