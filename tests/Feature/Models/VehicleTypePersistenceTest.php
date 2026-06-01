<?php

use App\Models\Vehicle;
use App\Models\VehicleType;

it('persists a vehicle for every seeded vehicle type', function () {
    $types = VehicleType::query()->get();

    expect($types)->not->toBeEmpty();

    foreach ($types as $type) {
        $vehicle = Vehicle::factory()->create(['vehicle_type_id' => $type->id]);

        expect($vehicle->fresh()->vehicleType->code)->toBe($type->code);
    }
});

it('seeds the microbus type with C1/C2/C3 license categories', function () {
    $microbus = VehicleType::query()->where('code', 'microbus')->first();

    expect($microbus)->not->toBeNull()
        ->and($microbus->allowed_license_categories)->toEqualCanonicalizing(['C1', 'C2', 'C3']);
});
