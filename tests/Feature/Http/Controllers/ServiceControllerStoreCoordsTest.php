<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Coords-source/accuracy/place-id storage and validation. Companion to
 * the Google Maps migration (ENH-001): origin/destination coordinates
 * travel with a `_source` discriminator ('google' or 'manual'), an
 * optional `_accuracy` string (a Google Geocoder `location_type`), and
 * an optional `_place_id` (the durable Google Place ID). All three
 * columns are nullable for manual pins and legacy records.
 */
beforeEach(function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    actingAs($admin);
});

function buildStoreCoordsPayload(array $overrides = []): array
{
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
    $origin = Municipality::factory()->create();
    $destination = Municipality::factory()->create();

    return array_merge([
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'origin_municipality_id' => $origin->id,
        'origin_address' => 'Calle 41A Sur #83-17',
        'origin_coordinates' => '4.5816950,-74.1784720',
        'origin_coordinates_source' => 'google',
        'origin_coordinates_accuracy' => 'ROOFTOP',
        'origin_place_id' => 'ChIJaY1z8KcZP44Rk5lEZJrKn2Q',
        'destination_municipality_id' => $destination->id,
        'destination_address' => 'Carrera 11 #82-71',
        'destination_coordinates' => '4.6679000,-74.0541000',
        'destination_coordinates_source' => 'manual',
        'destination_coordinates_accuracy' => null,
        'destination_place_id' => null,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 120,
        'unit_value' => 250000,
        'quantity' => 1,
        'billing_groups' => ['Salud'],
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('store accepts google source with accuracy and place id', function (): void {
    post(route('services.store'), buildStoreCoordsPayload())
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->first();
    expect($service->origin_coordinates_source)->toBe('google');
    expect($service->origin_coordinates_accuracy)->toBe('ROOFTOP');
    expect($service->origin_place_id)->toBe('ChIJaY1z8KcZP44Rk5lEZJrKn2Q');
});

test('store accepts manual source with null accuracy and null place id', function (): void {
    post(route('services.store'), buildStoreCoordsPayload())
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->first();
    expect($service->destination_coordinates_source)->toBe('manual');
    expect($service->destination_coordinates_accuracy)->toBeNull();
    expect($service->destination_place_id)->toBeNull();
});

test('store rejects malformed coordinates with 422', function (): void {
    post(
        route('services.store'),
        buildStoreCoordsPayload(['origin_coordinates' => 'not-a-coord'])
    )->assertStatus(302)->assertSessionHasErrors(['origin_coordinates']);
});

test('store rejects the removed mapbox coordinate source', function (): void {
    post(
        route('services.store'),
        buildStoreCoordsPayload(['origin_coordinates_source' => 'mapbox'])
    )->assertStatus(302)->assertSessionHasErrors(['origin_coordinates_source']);
});

test('store rejects fully empty origin and destination municipalities', function (): void {
    // Origin and destination cities are now REQUIRED. Nulling the
    // municipality ids (and addresses/coords) must fail validation on
    // both *_municipality_id fields.
    post(
        route('services.store'),
        buildStoreCoordsPayload([
            'origin_address' => null,
            'origin_municipality_id' => null,
            'origin_coordinates' => null,
            'origin_coordinates_source' => null,
            'origin_coordinates_accuracy' => null,
            'origin_place_id' => null,
            'destination_address' => null,
            'destination_municipality_id' => null,
            'destination_coordinates' => null,
            'destination_coordinates_source' => null,
            'destination_coordinates_accuracy' => null,
            'destination_place_id' => null,
        ])
    )
        ->assertStatus(302)
        ->assertSessionHasErrors([
            'origin_municipality_id',
            'destination_municipality_id',
        ]);
});

test('store rejects address text without coordinates (origin)', function (): void {
    post(
        route('services.store'),
        buildStoreCoordsPayload([
            'origin_address' => 'Calle inventada sin pin',
            'origin_coordinates' => null,
            'origin_coordinates_source' => null,
        ])
    )
        ->assertStatus(302)
        ->assertSessionHasErrors([
            'origin_coordinates',
            'origin_coordinates_source',
        ]);
});

test('store rejects address text without coordinates (destination)', function (): void {
    post(
        route('services.store'),
        buildStoreCoordsPayload([
            'destination_address' => 'Otra calle sin pin',
            'destination_coordinates' => null,
            'destination_coordinates_source' => null,
        ])
    )
        ->assertStatus(302)
        ->assertSessionHasErrors([
            'destination_coordinates',
            'destination_coordinates_source',
        ]);
});

test('store rejects address text with coords but missing source', function (): void {
    post(
        route('services.store'),
        buildStoreCoordsPayload([
            'origin_address' => 'Calle 41A Sur #83-17',
            'origin_coordinates' => '4.6,-74.1',
            'origin_coordinates_source' => null,
        ])
    )
        ->assertStatus(302)
        ->assertSessionHasErrors(['origin_coordinates_source']);
});

test('update can switch source from google to manual and clears the place id', function (): void {
    $service = Service::factory()->create([
        'origin_coordinates' => '4.5816950,-74.1784720',
        'origin_coordinates_source' => 'google',
        'origin_coordinates_accuracy' => 'ROOFTOP',
        'origin_place_id' => 'ChIJaY1z8KcZP44Rk5lEZJrKn2Q',
    ]);

    $payload = buildStoreCoordsPayload([
        'origin_coordinates' => '4.6000000,-74.1000000',
        'origin_coordinates_source' => 'manual',
        'origin_coordinates_accuracy' => null,
        'origin_place_id' => null,
    ]);

    put(route('services.update', $service), $payload)
        ->assertRedirect();

    $service->refresh();
    expect($service->origin_coordinates_source)->toBe('manual');
    expect($service->origin_coordinates_accuracy)->toBeNull();
    expect($service->origin_place_id)->toBeNull();
    expect($service->origin_coordinates)->toBe('4.6000000,-74.1000000');
});
