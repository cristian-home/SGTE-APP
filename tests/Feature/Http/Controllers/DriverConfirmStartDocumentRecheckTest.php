<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\LicenseCategory;
use App\Enums\Role;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tz;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * REQ-004 / REQ-005 execution-time regression for
 * document-expiry-service-date-recheck. Each branch confirms that
 * confirmStart 422s with a Spanish message naming the expired doc when
 * paperwork has expired as-of service.service_date.
 *
 * Note: post-2026-05-08 the driver dashboard rejects any action on a
 * service whose `service_date_local` is not today (see
 * `assertActionAllowedToday`). All scenarios below therefore fix
 * `service_date` to today_op and craft document-expiry conditions
 * relative to today_op as well.
 */
beforeEach(function (): void {
    $driverUser = User::factory()->create();
    $driverUser->assignRole(Role::DRIVER->value);
    $this->driverUser = $driverUser;
    $this->todayOp = Carbon::now(Tz::operation())->toDateString();
    $this->yesterdayOp = Carbon::now(Tz::operation())->subDay()->toDateString();
});

function buildValidFleet(array $vehicleOverrides = [], array $driverOverrides = []): array
{
    $vehicle = Vehicle::factory()->create([
        'vehicle_type_id' => vtid('van'),
        'soat_due_date' => '2030-12-31',
        'rtm_due_date' => '2030-12-31',
        'operation_card_due_date' => '2030-12-31',
        ...$vehicleOverrides,
    ]);

    $driver = Driver::factory()->create([
        'license_category' => LicenseCategory::C2,
        'license_due_date' => '2030-12-31',
        'has_social_security' => true,
        ...$driverOverrides,
    ]);

    return [$vehicle, $driver];
}

test('confirmStart succeeds when all documents are valid as-of service_date', function (): void {
    [$vehicle, $driver] = buildValidFleet();
    $driver->user_id = $this->driverUser->id;
    $driver->save();

    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    post(route('driver.confirm-start', $service))
        ->assertRedirect(route('driver.dashboard'));
});

test('confirmStart 422s when SOAT expired before service_date (REQ-004 branch)', function (): void {
    [$vehicle, $driver] = buildValidFleet(['soat_due_date' => $this->yesterdayOp]);
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('SOAT');
});

test('confirmStart 422s when RTM expired before service_date (REQ-004 branch)', function (): void {
    [$vehicle, $driver] = buildValidFleet(['rtm_due_date' => $this->yesterdayOp]);
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('RTM');
});

test('confirmStart 422s when operation card expired before service_date (REQ-004 branch)', function (): void {
    [$vehicle, $driver] = buildValidFleet(['operation_card_due_date' => $this->yesterdayOp]);
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('Tarjeta de Operación');
});

test('confirmStart 422s when driver license expired before service_date (REQ-005 branch)', function (): void {
    [$vehicle, $driver] = buildValidFleet([], ['license_due_date' => $this->yesterdayOp]);
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('licencia');
});

test('confirmStart 422s when driver license category is incompatible with vehicle type (REQ-005 branch)', function (): void {
    // Bus requires C2 or C3; use C1 to trigger incompatibility.
    [$vehicle, $driver] = buildValidFleet(
        ['vehicle_type_id' => vtid('bus')],
        ['license_category' => LicenseCategory::C1],
    );
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('categoría');
});

test('confirmStart 422s when driver social security is inactive (REQ-005 branch)', function (): void {
    [$vehicle, $driver] = buildValidFleet([], ['has_social_security' => false]);
    $driver->user_id = $this->driverUser->id;
    $driver->save();
    $service = Service::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_date' => $this->todayOp,
        'actual_start_time' => null,
    ]);

    actingAs($this->driverUser);

    $response = post(route('driver.confirm-start', $service));
    $response->assertStatus(422);
    expect($response->exception?->getMessage() ?? '')->toContain('seguridad social');
});
