<?php

use App\Enums\DayStatusEnum;
use App\Enums\ServiceStatus;
use App\Models\Contract;
use App\Models\DayStatus;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\delete;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $this->serviceDate = Carbon::today()->toDateString();

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $this->vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $this->driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);

    $this->service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => $this->serviceDate,
        'planned_start_time' => '08:00',
        'planned_duration' => 120,
        'service_status' => ServiceStatus::Closed,
    ]);
});

function validUpdateData(object $test): array
{
    return [
        'contract_id' => $test->contract->id,
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $test->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => $test->serviceDate.' 10:00',
        'planned_duration' => 90,
        'unit_value' => 100000,
        'quantity' => 2,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => $test->serviceDate.' 10:00',
        'actual_end' => $test->serviceDate.' 11:30',
    ];
}

test('operator can update a service on a projected day', function (): void {
    $user = User::factory()->create();
    $user->assignRole('operator');
    $this->actingAs($user);

    // DayStatus is auto-created as projected by the observer

    $response = put(route('services.update', $this->service), validUpdateData($this));

    $response->assertRedirect(route('services.index'));
});

test('operator cannot update a service on an executed day', function (): void {
    $user = User::factory()->create();
    $user->assignRole('operator');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = put(route('services.update', $this->service), validUpdateData($this));

    $response->assertForbidden();
});

test('accounting can update billing fields on an executed day', function (): void {
    $user = User::factory()->create();
    $user->assignRole('accounting');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = put(route('services.update', $this->service), [
        'unit_value' => 200000,
        'quantity' => 3,
        'payment_method' => 'cash',
        'billing_groups' => ['Salud'],
    ]);

    $response->assertRedirect(route('services.index'));

    $this->service->refresh();
    expect($this->service->quantity)->toBe(3);
});

test('admin can update any field on an executed day with justification', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $data = validUpdateData($this);
    $data['justification'] = 'Corrección necesaria por error en la asignación del vehículo.';

    $response = put(route('services.update', $this->service), $data);

    $response->assertRedirect(route('services.index'));
});

test('admin update on executed day without justification fails validation', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = put(route('services.update', $this->service), validUpdateData($this));

    $response->assertSessionHasErrors('justification');
});

test('admin update on executed day with justification creates activity log entry', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $data = validUpdateData($this);
    $data['justification'] = 'Corrección necesaria por error en la asignación del vehículo.';

    put(route('services.update', $this->service), $data);

    $activity = Activity::where('description', 'Servicio editado en día ejecutado')
        ->where('subject_type', Service::class)
        ->where('subject_id', $this->service->id)
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties['justification'])->toBe($data['justification']);
    expect($activity->properties['edited_on_executed_day'])->toBeTrue();
});

test('admin update on executed day with justification writes exactly one justification entry keyed to the admin causer', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $admin->id,
        'executed_at' => now(),
    ]);

    $data = validUpdateData($this);
    $data['justification'] = 'Corrección de fecha por error de captura inicial — aprobado por supervisor.';

    // Snapshot the count of "justification-bearing" activities before the request
    // so the strict "exactly one" assertion below excludes any factory-triggered
    // noise that could already be in the log (e.g. Service::created from setup).
    $justificationsBefore = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $this->service->id)
        ->whereJsonContains('properties->edited_on_executed_day', true)
        ->count();

    put(route('services.update', $this->service), $data);

    $justificationsAfter = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $this->service->id)
        ->whereJsonContains('properties->edited_on_executed_day', true)
        ->count();

    expect($justificationsAfter - $justificationsBefore)->toBe(1);

    $activity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $this->service->id)
        ->whereJsonContains('properties->edited_on_executed_day', true)
        ->latest('id')
        ->first();

    // REQ-009 AC#4: audit trail MUST record causer + justification.
    expect($activity->causer_id)->toBe($admin->id);
    expect($activity->properties['justification'])->toBe($data['justification']);
    expect($activity->properties['edited_on_executed_day'])->toBeTrue();
});

test('creating a service on an executed day is rejected for non-admin roles', function (): void {
    // Post-BUG-03 (bug-log:BUG-03): Admin / Super Admin can late-add on an
    // EJECUTADO day when supplying a 10–500 char justification. Non-admin
    // roles remain hard-blocked with a service_date error — that's what
    // this test pins. Admin + justification path is covered by
    // tests/Browser/E2eHardCasesTest.php::SVC-LC-17.
    $user = User::factory()->create();
    $user->assignRole('operator');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => $this->serviceDate.' 14:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertSessionHasErrors('service_date');
});

test('deleting a service on an executed day by operator is rejected', function (): void {
    $user = User::factory()->create();
    $user->assignRole('operator');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = delete(route('services.destroy', $this->service));

    $response->assertForbidden();
});

test('deleting a service on an executed day by admin succeeds', function (): void {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $this->actingAs($user);

    DayStatus::whereDate('date', $this->serviceDate)->update([
        'status' => DayStatusEnum::Executed,
        'executor_id' => $user->id,
        'executed_at' => now(),
    ]);

    $response = delete(route('services.destroy', $this->service));

    $response->assertRedirect(route('services.index'));

    $this->service->refresh();
    expect($this->service->trashed())->toBeTrue();
});
