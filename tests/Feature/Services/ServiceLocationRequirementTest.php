<?php

namespace Tests\Feature\Services;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Rules\FuecPreGenerationChecks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

use function Pest\Laravel\post;

/**
 * Origin/destination location policy: the CITY (municipality) is required on
 * both ends; the precise address + pin stay optional. When only the city is
 * given, the backend fills the coordinates from the municipality centroid
 * (source 'centroid'). FUEC generation is blocked when a city is missing.
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
    $this->vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $this->driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'has_social_security' => true,
    ]);
    $this->originCity = Municipality::factory()->create([
        'latitude' => 4.65000000,
        'longitude' => -74.05000000,
    ]);
    $this->destCity = Municipality::factory()->create([
        'latitude' => 6.25000000,
        'longitude' => -75.56000000,
    ]);
});

function locationPayload(array $overrides = []): array
{
    return array_merge([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'timezone' => 'America/Bogota',
        'planned_start' => Carbon::tomorrow()->toDateString().' 08:00',
        'planned_end' => Carbon::tomorrow()->toDateString().' 10:00',
        'origin_municipality_id' => test()->originCity->id,
        'destination_municipality_id' => test()->destCity->id,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('rejects creating a service without origin and destination cities', function (): void {
    post(route('services.store'), locationPayload([
        'origin_municipality_id' => null,
        'destination_municipality_id' => null,
    ]))->assertSessionHasErrors(['origin_municipality_id', 'destination_municipality_id']);

    expect(Service::query()->count())->toBe(0);
});

test('city only (no address/pin) fills the coordinates from the municipality centroid', function (): void {
    post(route('services.store'), locationPayload())->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();

    expect($service->origin_coordinates_source)->toBe('centroid')
        ->and($service->destination_coordinates_source)->toBe('centroid')
        ->and($service->origin_coordinates)->toBe("{$this->originCity->latitude},{$this->originCity->longitude}")
        ->and($service->destination_coordinates)->toBe("{$this->destCity->latitude},{$this->destCity->longitude}")
        ->and($service->origin_address)->toBeNull();
});

test('an address without confirmed coordinates is still rejected', function (): void {
    post(route('services.store'), locationPayload([
        'origin_address' => 'Calle 100 #15-20',
    ]))->assertSessionHasErrors(['origin_coordinates']);

    expect(Service::query()->count())->toBe(0);
});

test('a precise pin is kept and not overwritten by the centroid', function (): void {
    post(route('services.store'), locationPayload([
        'origin_address' => 'Aeropuerto El Dorado',
        'origin_coordinates' => '4.7010000,-74.1469000',
        'origin_coordinates_source' => 'manual',
    ]))->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();

    expect($service->origin_coordinates)->toBe('4.7010000,-74.1469000')
        ->and($service->origin_coordinates_source)->toBe('manual')
        // destination still falls back to its centroid
        ->and($service->destination_coordinates_source)->toBe('centroid');
});

test('FUEC pre-generation is blocked when the service has no cities', function (): void {
    $service = Service::factory()->withoutLocation()->create(['service_status' => 'closed']);

    $validator = Validator::make([], []);
    (new FuecPreGenerationChecks($service))->run($validator);

    expect($validator->errors()->has('fuec_pre_generation.location'))->toBeTrue();
});

test('FUEC pre-generation passes the location check when both cities are present', function (): void {
    $service = Service::factory()->create(['service_status' => 'closed']);

    $validator = Validator::make([], []);
    (new FuecPreGenerationChecks($service))->run($validator);

    expect($validator->errors()->has('fuec_pre_generation.location'))->toBeFalse();
});
