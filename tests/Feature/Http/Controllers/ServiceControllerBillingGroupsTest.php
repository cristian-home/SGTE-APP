<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);
});

function buildBillingGroupsPayload(array $billingGroups): array
{
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
    $originMunicipality = Municipality::factory()->create();
    $destinationMunicipality = Municipality::factory()->create();

    return [
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'origin_municipality_id' => $originMunicipality->id,
        'origin_address' => 'Calle 1',
        'origin_coordinates' => '4.5816950,-74.1784720',
        'origin_coordinates_source' => 'manual',
        'destination_municipality_id' => $destinationMunicipality->id,
        'destination_address' => 'Carrera 1',
        'destination_coordinates' => '4.6679000,-74.0541000',
        'destination_coordinates_source' => 'manual',
        'planned_start' => Carbon::today()->toDateString().' 08:00',
        'planned_duration' => 120,
        'unit_value' => 150000,
        'quantity' => 1,
        'billing_groups' => $billingGroups,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ];
}

test('store persists free-text billing-group tags verbatim', function (): void {
    post(route('services.store'), buildBillingGroupsPayload(['Salud', 'AC01']))
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->first();
    expect($service->billing_groups)->toBe(['Salud', 'AC01']);
});

test('store trims whitespace and drops empty tags', function (): void {
    post(route('services.store'), buildBillingGroupsPayload(['  Salud  ', '', '   ', 'AC01']))
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->first();
    expect($service->billing_groups)->toBe(['Salud', 'AC01']);
});

test('store deduplicates exact-match tags', function (): void {
    post(route('services.store'), buildBillingGroupsPayload(['Salud', 'Salud', 'AC01']))
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->first();
    expect($service->billing_groups)->toBe(['Salud', 'AC01']);
});

test('store rejects tags longer than 50 chars', function (): void {
    $payload = buildBillingGroupsPayload([str_repeat('X', 51)]);
    $response = post(route('services.store'), $payload);

    $response->assertSessionHasErrors('billing_groups.0');
});

test('update can clear billing_groups by sending an empty array', function (): void {
    $service = Service::factory()->create([
        'billing_groups' => ['Salud'],
    ]);

    put(route('services.update', $service), [
        ...buildBillingGroupsPayload([]),
        'billing_groups' => [],
    ])->assertRedirect(route('services.index'));

    $service->refresh();
    expect($service->billing_groups)->toBeNull();
});
