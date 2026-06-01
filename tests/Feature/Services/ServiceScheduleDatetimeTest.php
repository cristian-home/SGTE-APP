<?php

namespace Tests\Feature\Services;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * Datetime-picker rollout for the service schedule. Every business time
 * field (planned/actual start + end) is a full wall-clock datetime, so a
 * window that crosses midnight (e.g. 22:00 → 05:00 next day) is expressible
 * and validated correctly. Covers:
 *
 * - cross-midnight actual window accepted + persisted as instants,
 * - planned end on a later day derives a positive `planned_duration`,
 * - end-before-start rejected for both planned and actual windows,
 * - "no planning an open service in the past" (create-only, date-granular),
 * - retroactive closed-past still allowed (the past-date rule skips closed),
 * - editing a past service allows past datetimes.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonths(2),
        'end_date' => Carbon::now()->addMonths(2),
    ]);
    $this->vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $this->driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'has_social_security' => true,
    ]);
});

function schedulePayload(array $overrides = []): array
{
    return array_replace([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'timezone' => 'America/Bogota',
        'planned_start' => Carbon::tomorrow()->toDateString().' 22:00',
        'planned_end' => Carbon::tomorrow()->addDay()->toDateString().' 05:00',
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('planned window crossing midnight derives a positive duration', function (): void {
    post(route('services.store'), schedulePayload())->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();

    // 22:00 → 05:00 next day = 7h.
    expect($service->planned_duration)->toBe(420)
        ->and($service->planned_end_at->gt($service->planned_start_at))->toBeTrue();
});

test('omitting planned_end derives it from planned_start + planned_duration', function (): void {
    $payload = schedulePayload([
        'planned_start' => Carbon::tomorrow()->toDateString().' 22:00',
        'planned_duration' => 420,
    ]);
    unset($payload['planned_end']);

    post(route('services.store'), $payload)->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();
    expect($service->planned_duration)->toBe(420)
        // 22:00 + 420 min = 05:00 next day.
        ->and($service->planned_end_local)->toBe('05:00');
});

test('rejects a planned end that is not after the planned start', function (): void {
    $date = Carbon::tomorrow()->toDateString();
    post(route('services.store'), schedulePayload([
        'planned_start' => "{$date} 10:00",
        'planned_end' => "{$date} 09:00",
    ]))->assertSessionHasErrors(['planned_end']);

    expect(Service::query()->count())->toBe(0);
});

test('accepts a closed service whose actual window crosses midnight', function (): void {
    // A closed service with actual times is a retroactive (past) record, so
    // it dates in the past and carries a justification. The actual window
    // 22:10 → 05:05 next day exercises the midnight crossing.
    $start = Carbon::now('America/Bogota')->subDays(3);
    $date = $start->toDateString();
    $nextDay = $start->copy()->addDay()->toDateString();

    post(route('services.store'), schedulePayload([
        'service_status' => 'closed',
        'planned_start' => "{$date} 22:00",
        'planned_end' => "{$nextDay} 05:00",
        'actual_start' => "{$date} 22:10",
        'actual_end' => "{$nextDay} 05:05",
        'manual_entry_justification' => 'Servicio nocturno ejecutado sin acceso al sistema.',
    ]))->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();

    expect($service->actual_end_at->gt($service->actual_start_at))->toBeTrue()
        ->and($service->actual_start_local)->toBe('22:10')
        ->and($service->actual_end_local)->toBe('05:05');
});

test('rejects an actual end that is not after the actual start', function (): void {
    $date = Carbon::tomorrow()->toDateString();
    post(route('services.store'), schedulePayload([
        'service_status' => 'closed',
        'actual_start' => "{$date} 10:00",
        'actual_end' => "{$date} 09:00",
    ]))->assertSessionHasErrors(['actual_end']);

    expect(Service::query()->count())->toBe(0);
});

test('rejects creating an open service planned in the past', function (): void {
    // Three days back in the operation TZ — unambiguously before "today"
    // regardless of the host/UTC offset at run time.
    $past = Carbon::now('America/Bogota')->subDays(3)->toDateString();
    post(route('services.store'), schedulePayload([
        'planned_start' => "{$past} 22:00",
        'planned_end' => "{$past} 23:30",
    ]))->assertSessionHasErrors(['planned_start']);

    expect(Service::query()->count())->toBe(0);
});

test('allows creating an open service planned for today at any time', function (): void {
    // Date-granular rule: today is allowed even at an early hour.
    $today = Carbon::today()->toDateString();
    post(route('services.store'), schedulePayload([
        'planned_start' => "{$today} 06:00",
        'planned_end' => "{$today} 08:00",
    ]))->assertRedirect(route('services.index'));

    expect(Service::query()->count())->toBe(1);
});

test('editing a past service allows past datetimes', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->subWeek()->toDateString(),
        'planned_start_time' => '08:00',
        'planned_duration' => 120,
        'service_status' => 'open',
    ]);

    $pastDate = Carbon::now()->subWeek()->toDateString();
    put(route('services.update', $service), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => "{$pastDate} 09:00",
        'planned_duration' => 90,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ])->assertSessionDoesntHaveErrors(['planned_start']);

    $service->refresh();
    expect($service->planned_start_local)->toBe('09:00')
        ->and($service->planned_duration)->toBe(90);
});

/**
 * Actual-vs-planned tolerance (ACTUAL_TOLERANCE_HOURS = 6): the actual
 * times may deviate from the planned window by up to 6 h on either side —
 * an early departure or a late finish — but not further. Exercised with a
 * past closed (retroactive) service so the records are valid to persist.
 */
function tolerancePayload(string $day, array $overrides = []): array
{
    return array_replace([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'timezone' => 'America/Bogota',
        'planned_start' => "{$day} 08:00",
        'planned_end' => "{$day} 10:00",
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => "{$day} 08:00",
        'actual_end' => "{$day} 10:00",
        'manual_entry_justification' => 'Registro retroactivo dentro de la verificación de tolerancia.',
    ], $overrides);
}

test('accepts an actual start exactly at the tolerance before the planned start', function (): void {
    // planned start 08:00 → lower bound 02:00 (6 h before). 02:00 is allowed.
    $day = Carbon::now('America/Bogota')->subDays(3)->toDateString();
    post(route('services.store'), tolerancePayload($day, [
        'actual_start' => "{$day} 02:00",
        'actual_end' => "{$day} 09:00",
    ]))->assertRedirect(route('services.index'));

    expect(Service::query()->count())->toBe(1);
});

test('rejects an actual start more than the tolerance before the planned start', function (): void {
    $day = Carbon::now('America/Bogota')->subDays(3)->toDateString();
    post(route('services.store'), tolerancePayload($day, [
        'actual_start' => "{$day} 01:00", // 7 h before planned start
        'actual_end' => "{$day} 09:00",
    ]))->assertSessionHasErrors(['actual_start']);

    expect(Service::query()->count())->toBe(0);
});

test('accepts an actual end exactly at the tolerance after the planned end', function (): void {
    // planned end 10:00 → upper bound 16:00 (6 h after). 16:00 is allowed.
    $day = Carbon::now('America/Bogota')->subDays(3)->toDateString();
    post(route('services.store'), tolerancePayload($day, [
        'actual_start' => "{$day} 08:00",
        'actual_end' => "{$day} 16:00",
    ]))->assertRedirect(route('services.index'));

    expect(Service::query()->count())->toBe(1);
});

test('rejects an actual end more than the tolerance after the planned end', function (): void {
    $day = Carbon::now('America/Bogota')->subDays(3)->toDateString();
    post(route('services.store'), tolerancePayload($day, [
        'actual_start' => "{$day} 08:00",
        'actual_end' => "{$day} 17:00", // 7 h after planned end
    ]))->assertSessionHasErrors(['actual_end']);

    expect(Service::query()->count())->toBe(0);
});
