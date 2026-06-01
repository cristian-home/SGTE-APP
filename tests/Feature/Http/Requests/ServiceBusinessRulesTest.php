<?php

namespace Tests\Feature\Http\Requests;

use App\Enums\LicenseCategory;
use App\Enums\VehicleType;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;

/**
 * REQ-003 / REQ-004 / REQ-005 acceptance criteria enforced server-side
 * in ServiceStoreRequest.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
});

function validStorePayload(array $overrides = []): array
{
    return array_merge([
        'contract_id' => test()->contract->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 10:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('rejects store when vehicle SOAT is expired', function (): void {
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'soat_due_date' => Carbon::now()->subDay(),
    ]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('rejects store when vehicle RTM is expired', function (): void {
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'rtm_due_date' => Carbon::now()->subDays(2),
    ]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('rejects store when vehicle operation card is expired', function (): void {
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'operation_card_due_date' => Carbon::now()->subDay(),
    ]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('rejects store when driver license is expired', function (): void {
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->subDay(),
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['driver_id']);
});

test('rejects store when driver lacks active social security', function (): void {
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create([
        'has_social_security' => false,
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['driver_id']);
});

test('rejects store when driver license category is incompatible with vehicle type', function (): void {
    // Bus requires C2 or C3; a C1 license should be rejected.
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'type' => VehicleType::Bus,
    ]);
    $driver = Driver::factory()->create([
        'license_category' => LicenseCategory::C1,
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionHasErrors(['driver_id']);
});

test('accepts store when driver license category is compatible with vehicle type', function (): void {
    // C1 is accepted for Automobile.
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'type' => VehicleType::Automobile,
    ]);
    $driver = Driver::factory()->create([
        'license_category' => LicenseCategory::C1,
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionDoesntHaveErrors(['driver_id', 'vehicle_id']);
});

test('accepts store for a Microbús with a compatible driver license', function (): void {
    // Microbús accepts C1/C2/C3 (same as Van). Regression guard for the
    // license map that originally omitted the Microbús type.
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'type' => VehicleType::Microbus,
    ]);
    $driver = Driver::factory()->create([
        'license_category' => LicenseCategory::C1,
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertSessionDoesntHaveErrors(['driver_id', 'vehicle_id']);
});

test('third-party vehicles bypass driver-license checks', function (): void {
    // Third-party vehicles have driver_id nulled in prepareForValidation,
    // so the driver-license path is not triggered even if a bad driver is
    // submitted. We still enforce vehicle-document expiry for the vehicle
    // itself (operator owns the schedule, not the vehicle).
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => true,
        'third_party_id' => $this->contract->third_party_id,
    ]);
    $expiredDriver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->subDay(),
    ]);

    $response = post(route('services.store'), validStorePayload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $expiredDriver->id,
    ]));

    $response->assertSessionDoesntHaveErrors(['driver_id']);
});
