<?php

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;

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
    $this->driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
});

function validServiceData(array $overrides = []): array
{
    // Accept the legacy `service_date` + `planned_start_time` override keys
    // and fold them into the single `planned_start` wall-clock datetime the
    // request now expects, so existing callsites stay terse.
    $date = $overrides['service_date'] ?? Carbon::now()->toDateString();
    $time = $overrides['planned_start_time'] ?? '10:00';
    unset($overrides['service_date'], $overrides['planned_start_time']);

    return array_merge([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => "{$date} {$time}",
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('no conflict when vehicle has no other services on the date', function (): void {
    $response = post(route('services.store'), validServiceData());

    $response->assertRedirect(route('services.index'));
    expect(Service::count())->toBe(1);
});

test('conflict detected when vehicle has overlapping service (partial overlap start)', function (): void {
    Service::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '09:30',
        'planned_duration' => 60,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('conflict detected when vehicle has overlapping service (partial overlap end)', function (): void {
    Service::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '10:30',
        'planned_duration' => 60,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('conflict detected when vehicle has fully enclosed service', function (): void {
    Service::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '10:15',
        'planned_duration' => 30,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 120,
    ]));

    $response->assertSessionHasErrors(['vehicle_id']);
});

test('no conflict when times are adjacent but not overlapping', function (): void {
    Service::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '08:00',
        'planned_duration' => 120,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]));

    $response->assertRedirect(route('services.index'));
});

test('no conflict when same vehicle has service on different date', function (): void {
    Service::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'service_date' => Carbon::now()->addDay()->toDateString(),
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]));

    $response->assertRedirect(route('services.index'));
});

test('excludeServiceId correctly excludes current service during edit', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]);

    $response = \Pest\Laravel\put(route('services.update', $service), validServiceData([
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]));

    $response->assertRedirect(route('services.index'));
});

test('driver conflict detection works', function (): void {
    $otherVehicle = Vehicle::factory()->create(['is_third_party' => false]);

    Service::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $otherVehicle->id,
        'service_date' => Carbon::now()->toDateString(),
        'planned_start_time' => '10:00',
        'planned_duration' => 60,
    ]);

    $response = post(route('services.store'), validServiceData([
        'planned_start_time' => '10:30',
        'planned_duration' => 60,
    ]));

    $response->assertSessionHasErrors(['driver_id']);
});
