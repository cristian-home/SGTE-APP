<?php

namespace Tests\Feature\Http\Requests;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\post;

/**
 * Regression for service-retroactive-entry-gating (REQ-009):
 *
 * - service_date >= today + status=closed → rejected. The driver
 *   workflow is the only legit path to Cerrado.
 * - service_date < today + status=closed + manual_entry_justification
 *   → accepted, justification persisted, activity_log entry tagged
 *   with source=retroactive_entry.
 * - service_date < today + status=closed + no justification → 422.
 * - service_date < today + status=closed + too-short justification
 *   → 422.
 * - Baseline: open-status creates on any date still work without a
 *   justification.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonths(2),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $this->vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $this->driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
    ]);
});

function retroactivePayload(array $overrides = []): array
{
    // Fold the legacy date + time override keys into the wall-clock
    // datetimes the request now expects (each actual time anchored to the
    // service day), keeping the per-test overrides terse.
    $date = array_key_exists('service_date', $overrides)
        ? $overrides['service_date']
        : Carbon::yesterday()->toDateString();
    $startTime = $overrides['planned_start_time'] ?? '08:00';
    $actualStart = array_key_exists('actual_start_time', $overrides)
        ? $overrides['actual_start_time']
        : '08:00';
    $actualEnd = array_key_exists('actual_end_time', $overrides)
        ? $overrides['actual_end_time']
        : '09:00';
    unset(
        $overrides['service_date'],
        $overrides['planned_start_time'],
        $overrides['actual_start_time'],
        $overrides['actual_end_time'],
    );

    return array_replace([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => "{$date} {$startTime}",
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => $actualStart === null ? null : "{$date} {$actualStart}",
        'actual_end' => $actualEnd === null ? null : "{$date} {$actualEnd}",
        'manual_entry_justification' => 'Servicio ejecutado sin acceso al sistema; registro histórico.',
    ], $overrides);
}

test('rejects today + closed create — driver workflow is the only path to Cerrado', function (): void {
    $response = post(route('services.store'), retroactivePayload([
        'service_date' => Carbon::today()->toDateString(),
    ]));

    $response->assertSessionHasErrors(['service_status']);
    $errors = session('errors')->get('service_status');
    expect(implode(' ', $errors))->toContain('no puede crearse en estado Cerrado');
    expect(Service::query()->count())->toBe(0);
});

test('rejects future + closed create', function (): void {
    $response = post(route('services.store'), retroactivePayload([
        'service_date' => Carbon::tomorrow()->toDateString(),
    ]));

    $response->assertSessionHasErrors(['service_status']);
    expect(Service::query()->count())->toBe(0);
});

test('accepts past + closed + valid justification; persists column + logs activity with source=retroactive_entry', function (): void {
    $response = post(route('services.store'), retroactivePayload());

    $response->assertRedirect(route('services.index'));

    $service = Service::query()->firstOrFail();
    expect($service->service_status->value)->toBe('closed');
    expect($service->manual_entry_justification)
        ->toContain('Servicio ejecutado sin acceso al sistema');

    $activity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $service->id)
        ->where('description', 'Registro retroactivo de servicio cerrado')
        ->first();

    expect($activity)->not->toBeNull();
    expect($activity->properties->get('source'))->toBe('retroactive_entry');
    expect($activity->properties->get('manual_entry_justification'))
        ->toContain('Servicio ejecutado sin acceso al sistema');
    expect($activity->properties->get('service_date_local'))
        ->toBe(Carbon::yesterday()->toDateString());
});

test('rejects past + closed + missing justification', function (): void {
    $response = post(route('services.store'), retroactivePayload([
        'manual_entry_justification' => null,
    ]));

    $response->assertSessionHasErrors(['manual_entry_justification']);
    expect(Service::query()->count())->toBe(0);
});

test('rejects past + closed + justification shorter than 10 characters', function (): void {
    $response = post(route('services.store'), retroactivePayload([
        'manual_entry_justification' => 'corto',
    ]));

    $response->assertSessionHasErrors(['manual_entry_justification']);
    expect(Service::query()->count())->toBe(0);
});

test('rejects past + open create — an open service cannot be planned in the past', function (): void {
    // With the datetime-picker rollout, planning an OPEN service in the
    // past is no longer allowed (validatePlannedStartNotPast). A past-dated
    // record must be entered as a CLOSED retroactive entry instead.
    $response = post(route('services.store'), retroactivePayload([
        'service_status' => 'open',
        'actual_start_time' => null,
        'actual_end_time' => null,
        'manual_entry_justification' => null,
    ]));

    $response->assertSessionHasErrors(['planned_start']);
    expect(Service::query()->count())->toBe(0);
});

test('allows today + open create without justification (baseline)', function (): void {
    $response = post(route('services.store'), retroactivePayload([
        'service_date' => Carbon::today()->toDateString(),
        'service_status' => 'open',
        'actual_start_time' => null,
        'actual_end_time' => null,
        'manual_entry_justification' => null,
    ]));

    $response->assertRedirect(route('services.index'));
    expect(Service::query()->count())->toBe(1);
});

test('an update that flips status to closed on a past-date service does not trigger the retroactive gate', function (): void {
    $service = Service::factory()->create([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'service_date' => Carbon::today()->toDateString(),
        'service_status' => 'open',
    ]);

    // Update path — the retroactive gate is scoped to create only,
    // so no justification is required here even though the resulting
    // state is (same-day, closed).
    $today = Carbon::today()->toDateString();
    $response = \Pest\Laravel\put(route('services.update', $service), [
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => "{$today} 08:00",
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => "{$today} 08:00",
        'actual_end' => "{$today} 09:00",
    ]);

    $response->assertSessionDoesntHaveErrors(['service_status', 'manual_entry_justification']);
});
