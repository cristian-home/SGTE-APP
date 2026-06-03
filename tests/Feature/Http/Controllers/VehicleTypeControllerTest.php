<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

test('index renders the catalog with seeded types', function (): void {
    $response = get(route('vehicle-types.index'));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('vehicle-types/index')
            ->has('vehicleTypes', VehicleType::count())
            ->has('licenseCategories', 3)
    );
});

test('store uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\VehicleTypeController::class,
        'store',
        \App\Http\Requests\VehicleTypeStoreRequest::class
    );

test('store saves and redirects', function (): void {
    $response = post(route('vehicle-types.store'), [
        'code' => 'lancha',
        'name' => 'Lancha',
        'allowed_license_categories' => ['C1', 'C2'],
        'active' => true,
        'sort_order' => 10,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $type = VehicleType::query()->where('code', 'lancha')->first();
    expect($type)->not->toBeNull()
        ->and($type->allowed_license_categories)->toEqualCanonicalizing(['C1', 'C2']);
});

test('store validates required and unique code', function (): void {
    post(route('vehicle-types.store'), [])->assertSessionHasErrors(['code', 'name']);

    post(route('vehicle-types.store'), [
        'code' => 'bus',
        'name' => 'Duplicado',
    ])->assertSessionHasErrors(['code']);
});

test('store rejects an invalid license category', function (): void {
    post(route('vehicle-types.store'), [
        'code' => 'x',
        'name' => 'X',
        'allowed_license_categories' => ['C9'],
    ])->assertSessionHasErrors(['allowed_license_categories.0']);
});

test('update saves and redirects', function (): void {
    $type = VehicleType::factory()->create(['code' => 'orig', 'name' => 'Orig']);

    $response = put(route('vehicle-types.update', $type), [
        'code' => 'orig',
        'name' => 'Actualizado',
        'allowed_license_categories' => ['C3'],
        'active' => false,
        'sort_order' => 5,
    ]);

    $response->assertRedirect();
    $type->refresh();
    expect($type->name)->toBe('Actualizado')
        ->and($type->active)->toBeFalse();
});

test('destroy is blocked while the type is in use', function (): void {
    $type = VehicleType::factory()->create();
    Vehicle::factory()->create(['vehicle_type_id' => $type->id]);

    $response = delete(route('vehicle-types.destroy', $type));

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(VehicleType::query()->whereKey($type->id)->exists())->toBeTrue();
});

test('destroy soft-deletes an unused type', function (): void {
    $type = VehicleType::factory()->create();

    delete(route('vehicle-types.destroy', $type))
        ->assertRedirect(route('vehicle-types.index'));

    expect(VehicleType::query()->whereKey($type->id)->exists())->toBeFalse();
});

test('unauthorized user cannot access the catalog', function (): void {
    $this->actingAs(User::factory()->create());

    get(route('vehicle-types.index'))->assertForbidden();
});

test('vehicles template lists one example row per active type', function (): void {
    $response = get('/admin/imports/templates/vehicles');

    $response->assertOk();
    $body = $response->getContent();

    foreach (VehicleType::query()->where('active', true)->pluck('code') as $code) {
        expect($body)->toContain(','.$code.',');
    }
});
