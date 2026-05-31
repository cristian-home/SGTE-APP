<?php

namespace Tests\Feature\Services;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Tests\Helpers\Tz;

use function Pest\Laravel\post;

/**
 * Cross-TZ harness for service creation. Ensures that the wall-clock
 * inputs (service_date + planned_start_time + timezone) always project
 * to the same UTC instant + service_date_local regardless of the host
 * PHP timezone the test is running under.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

dataset('host_timezones', Tz::crossTimezones());

test('service create persists planned_start_at + service_date_local consistently across host TZ', function (string $hostTz): void {
    Tz::with($hostTz, function () {
        $contract = Contract::factory()->create([
            'active' => true,
            'start_date' => '2026-04-01',
            'end_date' => '2026-12-31',
        ]);
        $vehicle = Vehicle::factory()->create([
            'is_third_party' => false,
            'soat_due_date' => '2026-12-31',
            'rtm_due_date' => '2026-12-31',
            'operation_card_due_date' => '2026-12-31',
        ]);
        $driver = Driver::factory()->create([
            'license_due_date' => '2026-12-31',
            'has_social_security' => true,
        ]);

        post(route('services.store'), [
            'contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'planned_start' => '2026-06-24 14:30',
            'timezone' => 'America/Bogota',
            'planned_duration' => 60,
            'unit_value' => 100000,
            'quantity' => 1,
            'payment_method' => 'credit',
            'service_status' => 'open',
        ])->assertRedirect();

        $service = Service::query()->latest('id')->firstOrFail();

        // 14:30 Bogotá (UTC-5) == 19:30 UTC.
        expect(Carbon::parse($service->planned_start_at)->utc()->toIso8601String())
            ->toBe('2026-06-24T19:30:00+00:00')
            ->and((string) $service->service_date_local->format('Y-m-d'))
            ->toBe('2026-06-24')
            ->and($service->timezone)
            ->toBe('America/Bogota');
    });
})->with('host_timezones');
