<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Municipality;
use App\Models\Service;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

test('index behaves as expected', function (): void {
    $vehicles = Vehicle::factory()->count(3)->create();

    $response = get(route('vehicles.index'));

    $response->assertOk();
});

test('index returns a paginated payload', function (): void {
    Vehicle::factory()->count(3)->create();

    $response = get(route('vehicles.index'));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('vehicles/index')
            ->has('vehicles.data', 3)
            ->has('vehicles.per_page')
            ->has('vehicles.current_page')
            ->has('vehicles.total')
    );
});

test('index filters by docs_status expired', function (): void {
    $today = Carbon::today();

    $expired = Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->subDay(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addDays(15),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addYear(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $response = get(route('vehicles.index', ['filter' => ['docs_status' => 'expired']]));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $expired->id)
    );
});

test('index filters by docs_status expiring_soon', function (): void {
    $today = Carbon::today();

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->subDay(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $expiringSoon = Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addDays(15),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addYear(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $response = get(route('vehicles.index', ['filter' => ['docs_status' => 'expiring_soon']]));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $expiringSoon->id)
    );
});

test('index filters by docs_status ok', function (): void {
    $today = Carbon::today();

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->subDay(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addDays(15),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $ok = Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addYear(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $response = get(route('vehicles.index', ['filter' => ['docs_status' => 'ok']]));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $ok->id)
    );
});

test('index filters by soat_expired boolean', function (): void {
    $today = Carbon::today();

    $soatExpired = Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->subDay(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addYear(),
        'rtm_due_date' => $today->copy()->subDay(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $response = get(route('vehicles.index', ['filter' => ['soat_expired' => 'true']]));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $soatExpired->id)
    );
});

test('per-document filters compose with docs_status via AND', function (): void {
    $today = Carbon::today();

    // Vehicle with expired SOAT only
    Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->subDay(),
        'rtm_due_date' => $today->copy()->addYear(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    // Vehicle with expired RTM only — matches docs_status=expired AND rtm_expired=true
    $expectedMatch = Vehicle::factory()->create([
        'soat_due_date' => $today->copy()->addYear(),
        'rtm_due_date' => $today->copy()->subDay(),
        'operation_card_due_date' => $today->copy()->addYear(),
    ]);

    $response = get(route('vehicles.index', [
        'filter' => [
            'docs_status' => 'expired',
            'rtm_expired' => 'true',
        ],
    ]));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('vehicles.data', 1)
            ->where('vehicles.data.0.id', $expectedMatch->id)
    );
});

test('index passes catalog data needed by the create modal', function (): void {
    $municipality = Municipality::factory()->create();

    $response = get(route('vehicles.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('municipalities')
        ->where('municipalities.0.department.id', $municipality->department_id)
        ->has('thirdParties')
    );
});

test('store uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\VehicleController::class,
        'store',
        \App\Http\Requests\VehicleStoreRequest::class
    );

test('store saves and redirects', function (): void {
    $internal_code = fake()->word();
    $plate = strtoupper(fake()->bothify('???###'));
    $mobile_number = fake()->word();
    $brand = fake()->word();
    $line = fake()->word();
    $model_year = fake()->numberBetween(2015, 2026);
    $type = fake()->randomElement(['bus', 'buseta', 'van', 'automobile']);
    $engine_number = fake()->word();
    $chassis_number = fake()->word();
    $capacity = fake()->numberBetween(4, 40);
    $municipality = \App\Models\Municipality::factory()->create();
    $is_third_party = fake()->boolean();
    $third_party = ThirdParty::factory()->create();
    $soat_due_date = Carbon::parse(fake()->date());
    $rtm_due_date = Carbon::parse(fake()->date());
    $operation_card_due_date = Carbon::parse(fake()->date());
    $status = fake()->randomElement(['active', 'maintenance', 'retired']);

    $response = post(route('vehicles.store'), [
        'internal_code' => $internal_code,
        'plate' => $plate,
        'mobile_number' => $mobile_number,
        'brand' => $brand,
        'line' => $line,
        'model_year' => $model_year,
        'vehicle_type_id' => vtid($type),
        'engine_number' => $engine_number,
        'chassis_number' => $chassis_number,
        'capacity' => $capacity,
        'municipality_id' => $municipality->id,
        'is_third_party' => $is_third_party,
        'third_party_id' => $third_party->id,
        'soat_due_date' => $soat_due_date,
        'rtm_due_date' => $rtm_due_date,
        'operation_card_due_date' => $operation_card_due_date,
        'status' => $status,
    ]);

    $vehicles = Vehicle::query()
        ->where('internal_code', $internal_code)
        ->where('plate', $plate)
        ->where('mobile_number', $mobile_number)
        ->where('brand', $brand)
        ->where('line', $line)
        ->where('model_year', $model_year)
        ->where('vehicle_type_id', vtid($type))
        ->where('engine_number', $engine_number)
        ->where('chassis_number', $chassis_number)
        ->where('capacity', $capacity)
        ->where('municipality_id', $municipality->id)
        ->where('is_third_party', $is_third_party)
        ->where('third_party_id', $third_party->id)
        ->where('status', $status)
        ->get();
    expect($vehicles)->toHaveCount(1);
    $vehicle = $vehicles->first();
    expect($vehicle->soat_due_date)->toBe($soat_due_date->format('Y-m-d'));
    expect($vehicle->rtm_due_date)->toBe($rtm_due_date->format('Y-m-d'));
    expect($vehicle->operation_card_due_date)->toBe($operation_card_due_date->format('Y-m-d'));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('show behaves as expected', function (): void {
    $vehicle = Vehicle::factory()->create();

    $response = get(route('vehicles.show', $vehicle));

    $response->assertOk();
});

test('show returns vehicle with relationships and recent services', function (): void {
    $municipality = Municipality::factory()->create();
    $thirdParty = ThirdParty::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'municipality_id' => $municipality->id,
        'is_third_party' => true,
        'third_party_id' => $thirdParty->id,
    ]);

    Service::factory()->count(7)->sequence(
        ['service_date' => Carbon::today()->subDays(1)],
        ['service_date' => Carbon::today()->subDays(2)],
        ['service_date' => Carbon::today()->subDays(3)],
        ['service_date' => Carbon::today()->subDays(4)],
        ['service_date' => Carbon::today()->subDays(5)],
        ['service_date' => Carbon::today()->subDays(6)],
        ['service_date' => Carbon::today()->subDays(7)],
    )->create([
        'vehicle_id' => $vehicle->id,
    ]);

    $response = get(route('vehicles.show', $vehicle));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('vehicles/show')
            ->where('vehicle.id', $vehicle->id)
            ->where('vehicle.municipality.id', $municipality->id)
            ->has('vehicle.municipality.department')
            ->where('vehicle.third_party.id', $thirdParty->id)
            ->has('recentServices', 5)
    );
});

test('show returns empty recentServices when none exist', function (): void {
    $vehicle = Vehicle::factory()->create();

    $response = get(route('vehicles.show', $vehicle));

    $response->assertOk();
    $response->assertInertia(
        fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('vehicles/show')
            ->has('recentServices', 0)
    );
});

test('show passes catalog data needed by the edit modal', function (): void {
    $vehicle = Vehicle::factory()->create();
    Municipality::factory()->create();

    $response = get(route('vehicles.show', $vehicle));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('municipalities')
        ->has('thirdParties')
    );
});

test('update uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\VehicleController::class,
        'update',
        \App\Http\Requests\VehicleUpdateRequest::class
    );

test('update redirects', function (): void {
    $vehicle = Vehicle::factory()->create();
    $internal_code = fake()->word();
    $plate = strtoupper(fake()->bothify('???###'));
    $mobile_number = fake()->word();
    $brand = fake()->word();
    $line = fake()->word();
    $model_year = fake()->numberBetween(2015, 2026);
    $type = fake()->randomElement(['bus', 'buseta', 'van', 'automobile']);
    $engine_number = fake()->word();
    $chassis_number = fake()->word();
    $capacity = fake()->numberBetween(4, 40);
    $municipality = \App\Models\Municipality::factory()->create();
    $is_third_party = fake()->boolean();
    $third_party = ThirdParty::factory()->create();
    $soat_due_date = Carbon::parse(fake()->date());
    $rtm_due_date = Carbon::parse(fake()->date());
    $operation_card_due_date = Carbon::parse(fake()->date());
    $status = fake()->randomElement(['active', 'maintenance', 'retired']);

    $response = put(route('vehicles.update', $vehicle), [
        'internal_code' => $internal_code,
        'plate' => $plate,
        'mobile_number' => $mobile_number,
        'brand' => $brand,
        'line' => $line,
        'model_year' => $model_year,
        'vehicle_type_id' => vtid($type),
        'engine_number' => $engine_number,
        'chassis_number' => $chassis_number,
        'capacity' => $capacity,
        'municipality_id' => $municipality->id,
        'is_third_party' => $is_third_party,
        'third_party_id' => $third_party->id,
        'soat_due_date' => $soat_due_date,
        'rtm_due_date' => $rtm_due_date,
        'operation_card_due_date' => $operation_card_due_date,
        'status' => $status,
    ]);

    $vehicle->refresh();

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect($internal_code)->toEqual($vehicle->internal_code);
    expect($plate)->toEqual($vehicle->plate);
    expect($mobile_number)->toEqual($vehicle->mobile_number);
    expect($brand)->toEqual($vehicle->brand);
    expect($line)->toEqual($vehicle->line);
    expect($model_year)->toEqual($vehicle->model_year);
    expect($type)->toEqual($vehicle->vehicleType->code);
    expect($engine_number)->toEqual($vehicle->engine_number);
    expect($chassis_number)->toEqual($vehicle->chassis_number);
    expect($capacity)->toEqual($vehicle->capacity);
    expect($municipality->id)->toEqual($vehicle->municipality_id);
    expect($is_third_party)->toEqual($vehicle->is_third_party);
    expect($third_party->id)->toEqual($vehicle->third_party_id);
    expect($soat_due_date->format('Y-m-d'))->toEqual($vehicle->soat_due_date);
    expect($rtm_due_date->format('Y-m-d'))->toEqual($vehicle->rtm_due_date);
    expect($operation_card_due_date->format('Y-m-d'))->toEqual($vehicle->operation_card_due_date);
    expect($status)->toEqual($vehicle->status->value);
});

test('destroy deletes and redirects', function (): void {
    $vehicle = Vehicle::factory()->create();

    $response = delete(route('vehicles.destroy', $vehicle));

    $response->assertRedirect(route('vehicles.index'));

    assertSoftDeleted($vehicle);
});

test('store fails when is_third_party is true without third_party_id', function (): void {
    $response = post(route('vehicles.store'), [
        'internal_code' => fake()->unique()->numerify('V-###'),
        'plate' => strtoupper(fake()->bothify('???###')),
        'mobile_number' => fake()->numerify('3#########'),
        'brand' => 'Chevrolet',
        'line' => 'NKR',
        'model_year' => 2024,
        'vehicle_type_id' => vtid('bus'),
        'engine_number' => fake()->bothify('??#####??##'),
        'chassis_number' => fake()->bothify('?????????????????'),
        'capacity' => 20,
        'municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'is_third_party' => true,
        'soat_due_date' => Carbon::now()->addYear()->toDateString(),
        'rtm_due_date' => Carbon::now()->addYear()->toDateString(),
        'operation_card_due_date' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors(['third_party_id']);
});

test('store succeeds when is_third_party is false without third_party_id', function (): void {
    $response = post(route('vehicles.store'), [
        'internal_code' => fake()->unique()->numerify('V-###'),
        'plate' => strtoupper(fake()->bothify('???###')),
        'mobile_number' => fake()->numerify('3#########'),
        'brand' => 'Toyota',
        'line' => 'Coaster',
        'model_year' => 2024,
        'vehicle_type_id' => vtid('buseta'),
        'engine_number' => fake()->bothify('??#####??##'),
        'chassis_number' => fake()->bothify('?????????????????'),
        'capacity' => 15,
        'municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'is_third_party' => false,
        'soat_due_date' => Carbon::now()->addYear()->toDateString(),
        'rtm_due_date' => Carbon::now()->addYear()->toDateString(),
        'operation_card_due_date' => Carbon::now()->addYear()->toDateString(),
        'status' => 'active',
    ]);

    $response->assertRedirect();
});
