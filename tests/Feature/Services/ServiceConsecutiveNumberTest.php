<?php

namespace Tests\Feature\Services;

use App\Models\Contract;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ServiceNumberSequence;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * User-facing service consecutive (`SRV-NNNN-YYYY`). Covers durable
 * reservation, monotonic increment, per-year reset, session reuse across
 * create-form reloads, persistence through store(), and the new datatable
 * date-range filters / sort.
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

function consecutivePayload(array $overrides = []): array
{
    return array_replace([
        'contract_id' => test()->contract->id,
        'vehicle_id' => test()->vehicle->id,
        'driver_id' => test()->driver->id,
        'origin_municipality_id' => Municipality::factory()->create()->id,
        'destination_municipality_id' => Municipality::factory()->create()->id,
        'timezone' => 'America/Bogota',
        'planned_start' => Carbon::tomorrow()->toDateString().' 08:00',
        'planned_end' => Carbon::tomorrow()->toDateString().' 10:00',
        'unit_value' => 100000,
        'quantity' => 1,
        'payment_method' => 'credit',
        'service_status' => 'open',
    ], $overrides);
}

test('reserveNextNumber increments monotonically and advances the counter', function (): void {
    $year = (int) Carbon::now(config('app.operation_tz'))->format('Y');

    expect(Service::reserveNextNumber($year))->toBe(sprintf('SRV-0001-%d', $year))
        ->and(Service::reserveNextNumber($year))->toBe(sprintf('SRV-0002-%d', $year))
        ->and(Service::reserveNextNumber($year))->toBe(sprintf('SRV-0003-%d', $year));

    expect(ServiceNumberSequence::where('year', $year)->value('last_number'))->toBe(3);
});

test('the sequence resets per year', function (): void {
    expect(Service::reserveNextNumber(2026))->toBe('SRV-0001-2026')
        ->and(Service::reserveNextNumber(2026))->toBe('SRV-0002-2026')
        ->and(Service::reserveNextNumber(2027))->toBe('SRV-0001-2027')
        ->and(Service::reserveNextNumber(2026))->toBe('SRV-0003-2026');
});

test('the create form reserves a consecutive and shows it read-only', function (): void {
    $response = get(route('services.create'));

    $response->assertOk();
    $reserved = $response->viewData('page')['props']['reservedServiceNumber'];

    expect($reserved)->toMatch('/^SRV-\d{4}-\d{4}$/');
});

test('reloading the create form reuses the same reservation (no burned gaps)', function (): void {
    $first = get(route('services.create'))->viewData('page')['props']['reservedServiceNumber'];
    $second = get(route('services.create'))->viewData('page')['props']['reservedServiceNumber'];

    expect($second)->toBe($first);
});

test('storing a service persists the reserved consecutive', function (): void {
    $reserved = get(route('services.create'))->viewData('page')['props']['reservedServiceNumber'];

    post(route('services.store'), consecutivePayload(['service_number' => $reserved]))
        ->assertRedirect(route('services.index'));

    $service = Service::query()->latest('id')->firstOrFail();
    expect($service->service_number)->toBe($reserved);
});

test('consecutive numbers are unique across many services', function (): void {
    $numbers = collect(range(1, 5))->map(function (int $i): string {
        // Stagger each service into its own two-hour slot so the
        // vehicle/driver scheduling guard doesn't reject the create.
        $start = Carbon::tomorrow()->setTime(6 + $i * 2, 0);
        $reserved = get(route('services.create'))->viewData('page')['props']['reservedServiceNumber'];
        post(route('services.store'), consecutivePayload([
            'service_number' => $reserved,
            'planned_start' => $start->format('Y-m-d H:i'),
            'planned_end' => $start->copy()->addHour()->format('Y-m-d H:i'),
        ]))->assertRedirect(route('services.index'));

        return Service::query()->latest('id')->firstOrFail()->service_number;
    });

    expect($numbers->unique()->count())->toBe(5);
});

test('a duplicate submitted consecutive does not collide thanks to the retry guard', function (): void {
    // Reuse a number that already exists: the store retry must re-reserve a
    // fresh one rather than 500 on the UNIQUE constraint.
    $existing = Service::factory()->create();

    post(route('services.store'), consecutivePayload(['service_number' => $existing->service_number]))
        ->assertRedirect(route('services.index'));

    $created = Service::query()->latest('id')->firstOrFail();
    expect($created->service_number)->not->toBe($existing->service_number);
});

test('the index filters by planned-start range and created range', function (): void {
    $tz = config('app.operation_tz');

    $early = Service::factory()->create([
        'planned_start_time' => '06:00',
        'service_date_local' => '2026-03-10',
    ]);
    $late = Service::factory()->create([
        'planned_start_time' => '06:00',
        'service_date_local' => '2026-09-20',
    ]);

    $filtered = get(route('services.index', ['filter' => ['planned_start_from' => '2026-09-01']]).'&', ['Accept' => 'application/json'])
        ->json('data');

    $ids = collect($filtered)->pluck('id');
    expect($ids)->toContain($late->id)
        ->and($ids)->not->toContain($early->id);
});

test('the index can sort by service_number', function (): void {
    Service::factory()->count(3)->create();

    $sorted = get(route('services.index', ['sort' => 'service_number']), ['Accept' => 'application/json'])
        ->json('data');

    $numbers = collect($sorted)->pluck('service_number')->all();
    $expected = $numbers;
    sort($expected);

    expect($numbers)->toBe($expected);
});
