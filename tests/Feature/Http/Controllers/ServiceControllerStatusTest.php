<?php

use App\Models\Contract;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\post;

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
    $this->driver = Driver::factory()->create(['license_due_date' => Carbon::now()->addYear()]);
});

test('store fails when service_status is closed and actual_start_time is missing', function (): void {
    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_end' => Carbon::now()->toDateString().' 10:00',
    ]);

    $response->assertSessionHasErrors(['actual_start']);
});

test('store fails when service_status is closed and actual_end_time is missing', function (): void {
    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => Carbon::now()->toDateString().' 08:00',
    ]);

    $response->assertSessionHasErrors(['actual_end']);
});

test('store succeeds when service_status is closed and both actual times are provided', function (): void {
    // REQ-009: a Cerrado create only clears the retroactive-entry gate
    // when the service_date is in the past and a manual_entry_justification
    // is supplied. The rule was introduced in the
    // service-retroactive-entry-gating requirement; this test was
    // updated from a same-day closed create (now rejected) to a past-
    // date back-fill with justification.
    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::yesterday()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'closed',
        'actual_start' => Carbon::yesterday()->toDateString().' 08:00',
        'actual_end' => Carbon::yesterday()->toDateString().' 09:30',
        'manual_entry_justification' => 'Registro histórico — el servicio se ejecutó sin acceso al sistema.',
    ]);

    $response->assertRedirect(route('services.index'));
});

test('store succeeds when service_status is open and actual times are null', function (): void {
    $response = post(route('services.store'), [
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'origin_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'destination_municipality_id' => \App\Models\Municipality::factory()->create()->id,
        'planned_start' => Carbon::now()->toDateString().' 08:00',
        'planned_duration' => 60,
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ]);

    $response->assertRedirect(route('services.index'));
});
