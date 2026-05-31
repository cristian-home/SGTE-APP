<?php

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\put;

/**
 * REQ-009 service_status transition invariant regression
 * (service-reopen-actual-time-invariant). Exercises every cell of the
 * {Open, Closed} × {Open, Closed} transition matrix and asserts the
 * post-update state of actual_start_time / actual_end_time matches
 * the documented invariant. Also asserts the activity log entry for
 * the transition captures status_from, status_to, cleared_fields,
 * and set_fields.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $this->vehicle = Vehicle::factory()->create(['is_third_party' => false]);
    $this->driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'has_social_security' => true,
    ]);
});

function reopenTransitionPayload(array $overrides = []): array
{
    $date = array_key_exists('service_date', $overrides)
        ? $overrides['service_date']
        : Carbon::now()->toDateString();
    $startTime = $overrides['planned_start_time'] ?? '08:00';
    $actualStart = array_key_exists('actual_start_time', $overrides) ? $overrides['actual_start_time'] : null;
    $actualEnd = array_key_exists('actual_end_time', $overrides) ? $overrides['actual_end_time'] : null;
    unset(
        $overrides['service_date'],
        $overrides['planned_start_time'],
        $overrides['actual_start_time'],
        $overrides['actual_end_time'],
    );

    $payload = array_merge([
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
        'service_status' => 'open',
    ], $overrides);

    if ($actualStart !== null) {
        $payload['actual_start'] = "{$date} {$actualStart}";
    }
    if ($actualEnd !== null) {
        $payload['actual_end'] = "{$date} {$actualEnd}";
    }

    return $payload;
}

test('Open → Open transition does not touch actual_*_time fields', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->toDateString(),
        'service_status' => 'open',
        'actual_start_time' => null,
        'actual_end_time' => null,
    ]);

    put(route('services.update', $service), reopenTransitionPayload([
        'service_status' => 'open',
    ]))->assertRedirect(route('services.index'));

    $service->refresh();
    expect($service->service_status->value)->toBe('open')
        ->and($service->actual_start_at)->toBeNull()
        ->and($service->actual_end_at)->toBeNull();

    $transitionActivity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $service->id)
        ->where('description', 'Servicio cambió de estado')
        ->first();
    expect($transitionActivity)->toBeNull();
});

test('Open → Closed transition requires both actual_*_time fields and persists them', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->toDateString(),
        'service_status' => 'open',
        'actual_start_time' => null,
        'actual_end_time' => null,
    ]);

    // Without actual_*_time on close → 422 on both fields.
    put(route('services.update', $service), reopenTransitionPayload([
        'service_status' => 'closed',
    ]))->assertSessionHasErrors(['actual_start', 'actual_end']);

    // Supplying both closes cleanly and writes the transition activity log.
    put(route('services.update', $service), reopenTransitionPayload([
        'service_status' => 'closed',
        'actual_start_time' => '08:00',
        'actual_end_time' => '09:30',
    ]))->assertRedirect(route('services.index'));

    $service->refresh();
    expect($service->service_status->value)->toBe('closed')
        ->and((string) $service->actual_start_local)->toContain('08:00')
        ->and((string) $service->actual_end_local)->toContain('09:30');

    $transitionActivity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $service->id)
        ->where('description', 'Servicio cambió de estado')
        ->first();
    expect($transitionActivity)->not->toBeNull()
        ->and($transitionActivity->properties['status_from'])->toBe('open')
        ->and($transitionActivity->properties['status_to'])->toBe('closed')
        ->and($transitionActivity->properties['set_fields'])->toContain('actual_start_at')
        ->and($transitionActivity->properties['set_fields'])->toContain('actual_end_at')
        ->and($transitionActivity->properties['cleared_fields'])->toBe([]);
});

test('Closed → Open transition clears actual_end_time but preserves actual_start_time', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->toDateString(),
        'service_status' => 'closed',
        'actual_start_time' => '08:00:00',
        'actual_end_time' => '09:30:00',
    ]);

    put(route('services.update', $service), reopenTransitionPayload([
        'service_status' => 'open',
        'actual_start_time' => '08:00',
        // Even if the client sends actual_end_time, the prepareForValidation
        // hook overrides it to null on the reopen transition.
        'actual_end_time' => '09:30',
    ]))->assertRedirect(route('services.index'));

    $service->refresh();
    expect($service->service_status->value)->toBe('open')
        ->and((string) $service->actual_start_local)->toContain('08:00')
        ->and($service->actual_end_at)->toBeNull();

    $transitionActivity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $service->id)
        ->where('description', 'Servicio cambió de estado')
        ->first();
    expect($transitionActivity)->not->toBeNull()
        ->and($transitionActivity->properties['status_from'])->toBe('closed')
        ->and($transitionActivity->properties['status_to'])->toBe('open')
        ->and($transitionActivity->properties['cleared_fields'])->toContain('actual_end_at')
        ->and($transitionActivity->properties['cleared_fields'])->not->toContain('actual_start_at')
        ->and($transitionActivity->properties['set_fields'])->toBe([]);
});

test('Closed → Closed transition leaves actual_*_time fields untouched', function (): void {
    $service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::now()->toDateString(),
        'service_status' => 'closed',
        'actual_start_time' => '08:00:00',
        'actual_end_time' => '09:30:00',
    ]);

    put(route('services.update', $service), reopenTransitionPayload([
        'service_status' => 'closed',
        'actual_start_time' => '08:00',
        'actual_end_time' => '09:30',
    ]))->assertRedirect(route('services.index'));

    $service->refresh();
    expect($service->service_status->value)->toBe('closed')
        ->and((string) $service->actual_start_local)->toContain('08:00')
        ->and((string) $service->actual_end_local)->toContain('09:30');

    $transitionActivity = Activity::query()
        ->where('subject_type', Service::class)
        ->where('subject_id', $service->id)
        ->where('description', 'Servicio cambió de estado')
        ->first();
    expect($transitionActivity)->toBeNull();
});
