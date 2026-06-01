<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

test('index behaves as expected', function (): void {
    $services = Service::factory()->count(3)->create();

    $response = get(route('services.index'));

    $response->assertOk();
});

test('index returns paginated inertia response', function (): void {
    Service::factory()->count(20)->create();

    $response = get(route('services.index'));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('services/index')
            ->has('services.data', 10)
            ->has('services.links')
            ->where('services.per_page', 10)
    );
});

test('index respects per_page parameter', function (): void {
    Service::factory()->count(10)->create();

    $response = get(route('services.index', ['per_page' => 5]));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 5)
            ->where('services.per_page', 5)
    );
});

test('index caps per_page at 100', function (): void {
    Service::factory()->count(5)->create();

    $response = get(route('services.index', ['per_page' => 500]));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('services.per_page', 100)
    );
});

test('index filters by search term', function (): void {
    Service::factory()->create(['origin_address' => 'Bogota', 'destination_address' => 'Cali']);
    Service::factory()->create(['origin_address' => 'Medellin', 'destination_address' => 'Pereira']);

    $response = get(route('services.index', ['filter[search]' => 'Bogota']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.origin_address', 'Bogota')
    );
});

test('index search is case insensitive', function (): void {
    Service::factory()->create(['origin_address' => 'Bogota', 'destination_address' => 'Cali']);

    $response = get(route('services.index', ['filter[search]' => 'bogota']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
    );
});

test('index search matches across multiple columns', function (): void {
    Service::factory()->create(['origin_address' => 'Cali', 'destination_address' => 'Bogota']);
    Service::factory()->create(['origin_address' => 'Medellin', 'destination_address' => 'Cali']);

    $response = get(route('services.index', ['filter[search]' => 'Cali']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 2)
    );
});

test('index combines search with other filters', function (): void {
    Service::factory()->create(['origin_address' => 'Bogota', 'destination_address' => 'Cali', 'service_status' => 'open']);
    Service::factory()->create(['origin_address' => 'Bogota', 'destination_address' => 'Cali', 'service_status' => 'closed']);

    $response = get(route('services.index', [
        'filter[search]' => 'Bogota',
        'filter[service_status]' => 'open',
    ]));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
    );
});

test('index returns json when Accept header is application/json', function (): void {
    Service::factory()->count(3)->create();

    $response = getJson(route('services.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
            'last_page',
            'links',
        ]);
});

test('index json respects filters and sorting', function (): void {
    Service::factory()->create(['origin_address' => 'Bogota', 'destination_address' => 'Cali', 'service_status' => 'open']);
    Service::factory()->create(['origin_address' => 'Medellin', 'destination_address' => 'Pereira', 'service_status' => 'closed']);

    $response = getJson(route('services.index', [
        'filter[search]' => 'Bogota',
        'sort' => '-service_date_local',
    ]));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.origin_address', 'Bogota');
});

test('create behaves as expected', function (): void {
    $response = get(route('services.create'));

    $response->assertOk();
});

test('store uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\ServiceController::class,
        'store',
        \App\Http\Requests\ServiceStoreRequest::class
    );

test('store saves and redirects', function (): void {
    $service_date = Carbon::now()->toDateString();
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
    $invoice = Invoice::factory()->create();
    $origin_municipality = \App\Models\Municipality::factory()->create();
    $origin_address = fake()->streetAddress();
    $destination_municipality = \App\Models\Municipality::factory()->create();
    $destination_address = fake()->streetAddress();
    $planned_start_time = '08:00';
    $planned_duration = 120;
    $unit_value = fake()->randomFloat(2, 50000, 500000);
    $quantity = fake()->numberBetween(1, 5);
    $billing_groups = ['Salud', 'AC01'];
    $payment_method = fake()->randomElement(['cash', 'credit', 'transfer']);

    $response = post(route('services.store'), [
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'invoice_id' => $invoice->id,
        'origin_municipality_id' => $origin_municipality->id,
        'origin_address' => $origin_address,
        // address ↔ coords are required together (see ServiceStoreRequest).
        'origin_coordinates' => '4.5816950,-74.1784720',
        'origin_coordinates_source' => 'google',
        'origin_coordinates_accuracy' => 'ROOFTOP',
        'destination_municipality_id' => $destination_municipality->id,
        'destination_address' => $destination_address,
        'destination_coordinates' => '4.6679000,-74.0541000',
        'destination_coordinates_source' => 'manual',
        'planned_start' => $service_date.' '.$planned_start_time,
        'planned_duration' => $planned_duration,
        'unit_value' => $unit_value,
        'quantity' => $quantity,
        'billing_groups' => $billing_groups,
        'payment_method' => $payment_method,
        'service_status' => 'open',
    ]);

    $services = Service::query()
        ->where('contract_id', $contract->id)
        ->where('vehicle_id', $vehicle->id)
        ->where('driver_id', $driver->id)
        ->get();
    expect($services)->toHaveCount(1);

    $response->assertRedirect(route('services.index'));
});

test('show behaves as expected', function (): void {
    $service = Service::factory()->create();

    $response = get(route('services.show', $service));

    $response->assertOk();
});

test('edit behaves as expected', function (): void {
    $service = Service::factory()->create();

    $response = get(route('services.edit', $service));

    $response->assertOk();
});

test('update uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\ServiceController::class,
        'update',
        \App\Http\Requests\ServiceUpdateRequest::class
    );

test('update redirects', function (): void {
    $service_date = Carbon::now()->toDateString();
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
    $service = Service::factory()->create([
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $service_date,
        'planned_start_time' => '06:00',
        'planned_duration' => 60,
    ]);
    $origin_municipality = \App\Models\Municipality::factory()->create();
    $destination_municipality = \App\Models\Municipality::factory()->create();
    $origin_address = fake()->streetAddress();
    $unit_value = fake()->randomFloat(2, 50000, 500000);

    $response = put(route('services.update', $service), [
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'origin_municipality_id' => $origin_municipality->id,
        'destination_municipality_id' => $destination_municipality->id,
        'origin_address' => $origin_address,
        // address ↔ coords are required together (see ServiceStoreRequest).
        'origin_coordinates' => '4.5816950,-74.1784720',
        'origin_coordinates_source' => 'manual',
        'planned_start' => $service_date.' 10:00',
        'planned_duration' => 90,
        'unit_value' => $unit_value,
        'quantity' => 2,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $service->refresh();

    $response->assertRedirect(route('services.index'));

    expect($contract->id)->toEqual($service->contract_id);
    expect($vehicle->id)->toEqual($service->vehicle_id);
    expect($origin_municipality->id)->toEqual($service->origin_municipality_id);
    expect($origin_address)->toEqual($service->origin_address);
    expect('10:00')->toEqual($service->planned_start_local);
    expect(90)->toEqual($service->planned_duration);
    expect(2)->toEqual($service->quantity);
    expect('credit')->toEqual($service->payment_method->value);
});

test('destroy deletes and redirects', function (): void {
    $service = Service::factory()->create();

    $response = delete(route('services.destroy', $service));

    $response->assertRedirect(route('services.index'));

    assertSoftDeleted($service);
});

test('index can filter services by service_status', function (): void {
    Service::factory()->create(['service_status' => 'open']);
    Service::factory()->create(['service_status' => 'closed']);

    $response = get(route('services.index', ['filter[service_status]' => 'open']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.service_status', 'open')
    );
});

test('index can filter services by payment_method', function (): void {
    Service::factory()->create(['payment_method' => 'cash']);
    Service::factory()->create(['payment_method' => 'credit']);
    Service::factory()->create(['payment_method' => 'transfer']);

    $response = get(route('services.index', ['filter[payment_method]' => 'cash']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.payment_method', 'cash')
    );
});

test('index search returns results for partial terms', function (): void {
    Service::factory()->create(['origin_address' => 'Barranquilla', 'destination_address' => 'Cali']);
    Service::factory()->create(['origin_address' => 'Bucaramanga', 'destination_address' => 'Medellin']);

    $response = get(route('services.index', ['filter[search]' => 'Barran']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.origin_address', 'Barranquilla')
    );
});

test('index search matches related model fields via dot notation', function (): void {
    $driverCarlos = Driver::factory()->create(['first_name' => 'Carlos', 'first_lastname' => 'Gomez']);
    $driverMaria = Driver::factory()->create(['first_name' => 'Maria', 'first_lastname' => 'Lopez']);
    Service::factory()->create(['driver_id' => $driverCarlos->id, 'origin_address' => 'Bogota', 'destination_address' => 'Cali']);
    Service::factory()->create(['driver_id' => $driverMaria->id, 'origin_address' => 'Cali', 'destination_address' => 'Pereira']);

    $response = get(route('services.index', ['filter[search]' => 'Carlos']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.origin_address', 'Bogota')
    );
});

test('index search matches composite related fields with full name', function (): void {
    $driverCarlos = Driver::factory()->create(['first_name' => 'Carlos', 'first_lastname' => 'Gomez']);
    $driverCarlosL = Driver::factory()->create(['first_name' => 'Carlos', 'first_lastname' => 'Lopez']);
    Service::factory()->create(['driver_id' => $driverCarlos->id, 'origin_address' => 'Bogota', 'destination_address' => 'Cali']);
    Service::factory()->create(['driver_id' => $driverCarlosL->id, 'origin_address' => 'Medellin', 'destination_address' => 'Pereira']);

    $response = get(route('services.index', ['filter[search]' => 'Carlos Gomez']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.origin_address', 'Bogota')
    );
});

test('index can filter services by multiple service_status values', function (): void {
    Service::factory()->create(['service_status' => 'open']);
    Service::factory()->create(['service_status' => 'closed']);

    $response = get(route('services.index', ['filter[service_status]' => 'open,closed']));

    $response->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('services.data', 2)
    );
});

test('create returns view with vehicles, drivers, contracts, municipalities props', function (): void {
    $response = get(route('services.create'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('services/create')
        ->has('vehicles')
        ->has('drivers')
        ->has('contracts')
        ->has('municipalities')
    );
});

test('create excludes drivers with expired license', function (): void {
    Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'first_name' => 'ValidDriver',
    ]);
    Driver::factory()->create([
        'license_due_date' => Carbon::now()->subDay(),
        'first_name' => 'ExpiredDriver',
    ]);

    $response = get(route('services.create'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->has('drivers', 1)
        ->where('drivers.0.first_name', 'ValidDriver')
    );
});

test('store fails validation when required fields are missing', function (): void {
    $response = post(route('services.store'), []);

    $response->assertSessionHasErrors([
        'contract_id',
        'vehicle_id',
        'planned_start',
        'unit_value',
        'quantity',
        'payment_method',
        'service_status',
    ]);
});

test('store fails when contract is inactive', function (): void {
    $contract = Contract::factory()->create([
        'active' => false,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), [
        'contract_id' => $contract->id,
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

    $response->assertSessionHasErrors(['contract_id']);
});

test('store fails when contract date range does not cover service_date', function (): void {
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subYear(),
        'end_date' => Carbon::now()->subMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create();

    $response = post(route('services.store'), [
        'contract_id' => $contract->id,
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

    $response->assertSessionHasErrors(['contract_id']);
});

test('edit returns view with service and reference data', function (): void {
    $service = Service::factory()->create();

    $response = get(route('services.edit', $service));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('services/edit')
        ->has('service')
        ->has('vehicles')
        ->has('drivers')
        ->has('contracts')
        ->has('municipalities')
    );
});

test('show returns view with service and eager-loaded relationships', function (): void {
    $service = Service::factory()->create();

    $response = get(route('services.show', $service));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('services/show')
        ->has('service')
        ->has('service.contract')
        ->has('service.vehicle')
    );
});

test('show returns service with service incidents and their relationships', function (): void {
    $service = Service::factory()->create();
    \App\Models\ServiceIncident::factory()->count(2)->create([
        'service_id' => $service->id,
    ]);

    $response = get(route('services.show', $service));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('services/show')
        ->has('service.service_incidents', 2)
        ->has('service.service_incidents.0.incident_type')
        ->has('service.service_incidents.0.registrar')
        ->where('service.service_incidents_count', 2)
    );
});

test('unauthorized users cannot access create', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = get(route('services.create'));

    $response->assertForbidden();
});

test('unauthorized users cannot access store', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);

    $response = post(route('services.store'), [
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertForbidden();
});

test('unauthorized users cannot access edit', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $service = Service::factory()->create();

    $response = get(route('services.edit', $service));

    $response->assertForbidden();
});

test('unauthorized users cannot access update', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $service = Service::factory()->create();
    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);

    $response = put(route('services.update', $service), [
        'contract_id' => $contract->id,
        'vehicle_id' => $service->vehicle_id,
        'driver_id' => $service->driver_id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertForbidden();
});

test('unauthorized users cannot access destroy', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $service = Service::factory()->create();

    $response = delete(route('services.destroy', $service));

    $response->assertForbidden();
});
