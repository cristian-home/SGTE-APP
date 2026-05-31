<?php

namespace Tests\Feature\Services;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Tests\Helpers\Tz;

use function Pest\Laravel\post;

/**
 * REQ-004 / REQ-005 document checks must compare due dates against
 * service_date_local in the operation timezone — never the host PHP
 * TZ. Cross-host-TZ proof: a vehicle whose SOAT expired exactly on
 * service_date_local must always be rejected, regardless of where the
 * test process thinks "today" is.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    config(['app.operation_tz' => 'UTC']);
});

dataset('host_timezones', Tz::crossTimezones());

test('expired SOAT is flagged consistently across host TZ', function (string $hostTz): void {
    Tz::with($hostTz, function () {
        $contract = Contract::factory()->create([
            'active' => true,
            'start_date' => '2026-04-01',
            'end_date' => '2026-12-31',
        ]);
        $vehicle = Vehicle::factory()->create([
            'is_third_party' => false,
            'soat_due_date' => '2026-04-23', // expired on 2026-04-24
            'rtm_due_date' => '2026-12-31',
            'operation_card_due_date' => '2026-12-31',
        ]);
        $driver = Driver::factory()->create([
            'license_due_date' => '2026-12-31',
            'has_social_security' => true,
        ]);

        $response = post(route('services.store'), [
            'contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'planned_start' => '2026-04-24 14:30',
            'timezone' => 'UTC',
            'planned_duration' => 60,
            'unit_value' => 100000,
            'quantity' => 1,
            'payment_method' => 'credit',
            'service_status' => 'open',
        ]);

        $response->assertSessionHasErrors(['vehicle_id']);
        $errorBag = session('errors')?->get('vehicle_id') ?? [];
        expect(implode(' ', $errorBag))->toContain('SOAT');
    });
})->with('host_timezones');

test('expired driver license is flagged consistently across host TZ', function (string $hostTz): void {
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
            'license_due_date' => Carbon::parse('2026-04-23'),
            'has_social_security' => true,
        ]);

        $response = post(route('services.store'), [
            'contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'planned_start' => '2026-04-24 14:30',
            'timezone' => 'UTC',
            'planned_duration' => 60,
            'unit_value' => 100000,
            'quantity' => 1,
            'payment_method' => 'credit',
            'service_status' => 'open',
        ]);

        $response->assertSessionHasErrors(['driver_id']);
    });
})->with('host_timezones');
