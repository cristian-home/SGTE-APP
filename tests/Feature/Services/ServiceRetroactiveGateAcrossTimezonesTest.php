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
 * REQ-009 retroactive-entry gate must compare service_date_local against
 * "today in the operation timezone", never the host PHP TZ. The fixture
 * date is 2026-04-24 (operation TZ today). With operation_tz = UTC and
 * the host TZ in Tokyo (+9), today_in_Tokyo could already be 2026-04-25
 * — but the gate fires only when service_date_local < today_in_op_tz.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);

    config(['app.operation_tz' => 'UTC']);
    Carbon::setTestNow('2026-04-24 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('retroactive gate fires only on service_date_local < today_in_op_tz', function (): void {
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

    Tz::with('Asia/Tokyo', function () use ($contract, $vehicle, $driver) {
        // operation_tz is UTC; UTC today is 2026-04-24. A 2026-04-23
        // closed entry must require manual_entry_justification.
        $response = post(route('services.store'), [
            'contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
            'planned_start' => '2026-04-23 14:30',
            'timezone' => 'UTC',
            'planned_duration' => 60,
            'unit_value' => 100000,
            'quantity' => 1,
            'payment_method' => 'credit',
            'service_status' => 'closed',
            'actual_start' => '2026-04-23 14:30',
            'actual_end' => '2026-04-23 15:30',
        ]);

        $response->assertSessionHasErrors(['manual_entry_justification']);
    });
});

test('retroactive gate ignores host-TZ today shift when operation_tz is UTC', function (): void {
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

    // Today (UTC) is 2026-04-24. A 2026-04-24 closed entry is "today",
    // not in the past, so the gate must REJECT (closed-on-today rule),
    // regardless of host TZ where today might already be 2026-04-25.
    Tz::with('Asia/Tokyo', function () use ($contract, $vehicle, $driver) {
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
            'service_status' => 'closed',
            'actual_start' => '2026-04-24 14:30',
            'actual_end' => '2026-04-24 15:30',
        ]);

        $response->assertSessionHasErrors(['service_status']);
    });
});
