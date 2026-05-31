<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\DayStatusEnum;
use App\Models\DayStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

test('index redirects to calendar', function (): void {
    $response = get(route('day-statuses.index'));

    $response->assertRedirect(route('day-statuses.calendar', ['year' => now()->year]));
});

test('create behaves as expected', function (): void {
    $response = get(route('day-statuses.create'));

    $response->assertOk();
});

test('store uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\DayStatusController::class,
        'store',
        \App\Http\Requests\DayStatusStoreRequest::class
    );

test('store saves and redirects', function (): void {
    $date = Carbon::parse(fake()->date());
    $status = fake()->randomElement(['projected', 'executed']);
    $executor = User::factory()->create();
    $executed_at = Carbon::parse(fake()->dateTime());

    $response = post(route('day-statuses.store'), [
        'date' => $date,
        'status' => $status,
        'executor_id' => $executor->id,
        'executed_at' => $executed_at,
    ]);

    $dayStatuses = DayStatus::query()
        ->where('date', $date)
        ->where('status', $status)
        ->where('executor_id', $executor->id)
        ->where('executed_at', $executed_at)
        ->get();
    expect($dayStatuses)->toHaveCount(1);
    $dayStatus = $dayStatuses->first();

    $response->assertRedirect(route('day-statuses.index'));
});

test('show behaves as expected', function (): void {
    $dayStatus = DayStatus::factory()->create();

    $response = get(route('day-statuses.show', $dayStatus));

    $response->assertOk();
});

test('edit behaves as expected', function (): void {
    $dayStatus = DayStatus::factory()->create();

    $response = get(route('day-statuses.edit', $dayStatus));

    $response->assertOk();
});

test('update uses form request validation')
    ->assertActionUsesFormRequest(
        \App\Http\Controllers\DayStatusController::class,
        'update',
        \App\Http\Requests\DayStatusUpdateRequest::class
    );

test('update redirects', function (): void {
    // Deterministic forward transition (projected → executed). Using random
    // factory status + random target status was a coin-flip flake: when it
    // landed on executed → projected, the request's reversal rule requires a
    // justification (not sent here) and the controller clears the executor,
    // so the persistence assertions failed ~25% of runs.
    $dayStatus = DayStatus::factory()->create([
        'status' => DayStatusEnum::Projected,
    ]);
    $date = Carbon::parse('2026-03-15');
    $status = DayStatusEnum::Executed->value;
    $executor = User::factory()->create();
    $executed_at = Carbon::parse('2026-03-15 14:30:00');

    $response = put(route('day-statuses.update', $dayStatus), [
        'date' => $date,
        'status' => $status,
        'executor_id' => $executor->id,
        'executed_at' => $executed_at,
    ]);

    $dayStatus->refresh();

    $response->assertRedirect(route('day-statuses.index'));

    expect($date)->toEqual($dayStatus->date);
    expect($status)->toEqual($dayStatus->status->value);
    expect($executor->id)->toEqual($dayStatus->executor_id);
    expect($executed_at->timestamp)->toEqual($dayStatus->executed_at?->timestamp);
});

test('destroy deletes and redirects', function (): void {
    $dayStatus = DayStatus::factory()->create();

    $response = delete(route('day-statuses.destroy', $dayStatus));

    $response->assertRedirect(route('day-statuses.index'));

    assertModelMissing($dayStatus);
});
