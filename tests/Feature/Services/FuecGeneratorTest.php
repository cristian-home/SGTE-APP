<?php

use App\Enums\FuecStatus;
use App\Enums\LicenseCategory;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Fuec;
use App\Models\FuecNumberRange;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuecGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('s3');
    config()->set('sgte.fuec_enabled', true);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::ADMIN->value);
    actingAs($this->admin);

    $this->range = FuecNumberRange::factory()->active()->create([
        'resolution_number' => 'RES-TEST',
        'resolution_year' => (int) now()->format('Y'),
        'range_from' => 5000,
        'range_to' => 5100,
    ]);

    $this->contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);

    $this->vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'vehicle_type_id' => vtid('buseta'),
        'soat_due_date' => Carbon::now()->addYear(),
        'rtm_due_date' => Carbon::now()->addYear(),
        'operation_card_due_date' => Carbon::now()->addYear(),
    ]);

    $this->driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'license_category' => LicenseCategory::C2,
        'has_social_security' => true,
    ]);

    $this->service = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::today()->toDateString(),
        'service_status' => ServiceStatus::Closed,
    ]);
});

test('happy path creates a FUEC with the next consecutive and stores the PDF', function (): void {
    $generator = app(FuecGenerator::class);

    $fuec = $generator->generateFor($this->service, $this->admin);

    expect($fuec)->toBeInstanceOf(Fuec::class)
        ->and($fuec->consecutive_number)->toBe(5000)
        ->and($fuec->status)->toBe(FuecStatus::Active)
        ->and($fuec->uuid)->not->toBeEmpty()
        ->and($fuec->pdf_path)->toBe('fuecs/5000.pdf')
        ->and($fuec->pdf_disk)->toBe('s3')
        ->and($fuec->fuec_number_range_id)->toBe($this->range->id);

    Storage::disk('s3')->assertExists('fuecs/5000.pdf');

    $activity = Activity::query()
        ->where('subject_type', Fuec::class)
        ->where('subject_id', $fuec->id)
        ->where('description', 'FUEC generado')
        ->first();
    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['consecutive_number'])->toBe(5000);
});

test('subsequent generation uses the next consecutive in the active range', function (): void {
    $generator = app(FuecGenerator::class);

    $first = $generator->generateFor($this->service, $this->admin);
    expect($first->consecutive_number)->toBe(5000);

    $second = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::today()->toDateString(),
        'service_status' => ServiceStatus::Closed,
    ]);

    $fuec = $generator->generateFor($second, $this->admin);
    expect($fuec->consecutive_number)->toBe(5001);
});

test('service not closed is rejected with a Spanish validation error', function (): void {
    $this->service->update(['service_status' => ServiceStatus::Open]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('inactive contract is rejected', function (): void {
    $this->contract->update(['active' => false]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('expired SOAT is rejected', function (): void {
    $this->vehicle->update(['soat_due_date' => Carbon::now()->subDay()]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('expired RTM is rejected', function (): void {
    $this->vehicle->update(['rtm_due_date' => Carbon::now()->subDay()]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('expired operation card is rejected', function (): void {
    $this->vehicle->update(['operation_card_due_date' => Carbon::now()->subDay()]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('expired driver license is rejected', function (): void {
    $this->driver->update(['license_due_date' => Carbon::now()->subDay()]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('incompatible license category is rejected', function (): void {
    // Bus requires C2/C3; set the driver to C1 to trigger the category mismatch.
    $this->vehicle->update(['vehicle_type_id' => vtid('bus')]);
    $this->driver->update(['license_category' => LicenseCategory::C1]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('no active range is rejected', function (): void {
    $this->range->update(['active' => false]);

    expect(fn () => app(FuecGenerator::class)->generateFor($this->service, $this->admin))
        ->toThrow(ValidationException::class);
});

test('range exhausted throws FuecRangeExhaustedException', function (): void {
    // Shrink the range to a single number + consume it.
    $this->range->update(['range_from' => 5000, 'range_to' => 5000]);

    $first = app(FuecGenerator::class)->generateFor($this->service, $this->admin);
    expect($first->consecutive_number)->toBe(5000);

    $second = Service::factory()->create([
        'contract_id' => $this->contract->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'service_date' => Carbon::today()->toDateString(),
        'service_status' => ServiceStatus::Closed,
    ]);

    expect(fn () => app(FuecGenerator::class)->generateFor($second, $this->admin))
        ->toThrow(ValidationException::class);
});

test('generating a second FUEC for the same service auto-cancels the previous one (bug-log:BUG-06)', function (): void {
    $first = app(FuecGenerator::class)->generateFor($this->service, $this->admin);

    $second = app(FuecGenerator::class)->generateFor($this->service, $this->admin);

    expect($first->refresh()->status)->toBe(FuecStatus::Cancelled)
        ->and($first->cancellation_reason)->toBe('Superseded by new FUEC generation')
        ->and($second->status)->toBe(FuecStatus::Active);

    // Only one active FUEC remains for the service.
    expect(\App\Models\Fuec::query()
        ->where('service_id', $this->service->id)
        ->where('status', FuecStatus::Active)
        ->count())->toBe(1);
});

test('cancelling an active FUEC allows a new one to be generated for the same service', function (): void {
    $first = app(FuecGenerator::class)->generateFor($this->service, $this->admin);
    $first->update(['status' => FuecStatus::Cancelled]);

    $second = app(FuecGenerator::class)->generateFor($this->service, $this->admin);

    expect($second->consecutive_number)->toBe(5001)
        ->and($second->status)->toBe(FuecStatus::Active);
});
