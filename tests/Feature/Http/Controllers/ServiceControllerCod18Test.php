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
});

test('store succeeds without driver_id when vehicle is third-party', function (): void {
    $vehicle = Vehicle::factory()->create(['is_third_party' => true]);

    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $vehicle->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertRedirect(route('services.index'));
    expect(Service::first()->driver_id)->toBeNull();
});

test('store fails without driver_id when vehicle is NOT third-party', function (): void {
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);

    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $vehicle->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertSessionHasErrors(['driver_id']);
});

test('store sets driver_id to null when vehicle is third-party even if driver_id is provided', function (): void {
    $vehicle = Vehicle::factory()->create(['is_third_party' => true]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertRedirect(route('services.index'));
    expect(Service::first()->driver_id)->toBeNull();
});
