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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    Storage::fake('s3');
    config()->set('sgte.fuec_enabled', true);
});

function fuecReadyService(): Service
{
    $range = FuecNumberRange::query()->where('active', true)->first()
        ?? FuecNumberRange::factory()->active()->create(['range_from' => 5000, 'range_to' => 5100]);

    $contract = Contract::factory()->create([
        'active' => true,
        'start_date' => Carbon::now()->subMonth(),
        'end_date' => Carbon::now()->addMonth(),
    ]);
    $vehicle = Vehicle::factory()->create([
        'is_third_party' => false,
        'vehicle_type_id' => vtid('buseta'),
        'soat_due_date' => Carbon::now()->addYear(),
        'rtm_due_date' => Carbon::now()->addYear(),
        'operation_card_due_date' => Carbon::now()->addYear(),
    ]);
    $driver = Driver::factory()->create([
        'license_due_date' => Carbon::now()->addYear(),
        'license_category' => LicenseCategory::C2,
        'has_social_security' => true,
    ]);

    return Service::factory()->create([
        'contract_id' => $contract->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'service_status' => ServiceStatus::Closed,
    ]);
}

test('admin store creates a FUEC via the generator', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();

    post(route('fuecs.store'), ['service_id' => $service->id])
        ->assertRedirect();

    expect(Fuec::where('service_id', $service->id)->exists())->toBeTrue();
});

test('store rejects a service whose contract is inactive', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();
    $service->contract->update(['active' => false]);

    post(route('fuecs.store'), ['service_id' => $service->id])
        ->assertSessionHasErrorsIn('default', ['fuec_pre_generation.contract']);
});

test('non-admin receives 403 on fuec routes', function (): void {
    $operator = User::factory()->create();
    $operator->assignRole(Role::OPERATOR->value);
    actingAs($operator);

    get(route('fuecs.index'))->assertForbidden();
    get(route('fuecs.create'))->assertForbidden();
});

test('feature flag disabled 404s the fuec index', function (): void {
    config()->set('sgte.fuec_enabled', false);

    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    get(route('fuecs.index'))->assertNotFound();
});

test('pdf action streams the stored PDF with inline Content-Disposition', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();

    Storage::disk('s3')->put('fuecs/7777.pdf', 'PDFBYTES');

    $fuec = Fuec::factory()->create([
        'service_id' => $service->id,
        'fuec_number_range_id' => FuecNumberRange::query()->where('active', true)->first()->id,
        'consecutive_number' => 7777,
        'status' => FuecStatus::Active,
        'pdf_path' => 'fuecs/7777.pdf',
        'pdf_disk' => 's3',
    ]);

    $response = get(route('fuecs.pdf', $fuec));
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->headers->get('Content-Disposition'))->toContain('fuec-7777.pdf');
});

test('cancel action flips status to cancelled and writes an activity log entry', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();
    $fuec = Fuec::factory()->create([
        'service_id' => $service->id,
        'fuec_number_range_id' => FuecNumberRange::query()->where('active', true)->first()->id,
        'status' => FuecStatus::Active,
    ]);

    post(route('fuecs.cancel', $fuec), ['reason' => 'Anulación de prueba por error de captura en origen.'])
        ->assertRedirect();

    $fuec->refresh();
    expect($fuec->status)->toBe(FuecStatus::Cancelled)
        ->and($fuec->cancellation_reason)->toBe('Anulación de prueba por error de captura en origen.');

    $activity = Activity::query()
        ->where('subject_type', Fuec::class)
        ->where('subject_id', $fuec->id)
        ->where('description', 'FUEC anulado')
        ->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties['cancellation_reason'])->toBe('Anulación de prueba por error de captura en origen.');
});

test('cancel requires a reason (regression: no bypass via empty payload)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $fuec = Fuec::factory()->create([
        'fuec_number_range_id' => FuecNumberRange::factory()->create()->id,
        'status' => FuecStatus::Active,
    ]);

    post(route('fuecs.cancel', $fuec), [])
        ->assertSessionHasErrors('reason');

    $fuec->refresh();
    expect($fuec->status)->toBe(FuecStatus::Active)
        ->and($fuec->cancellation_reason)->toBeNull();
});

test('preview returns PDF without creating a Fuec row or consuming a consecutive (REQ-007)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();
    $fuecCountBefore = Fuec::count();

    $response = post(route('fuecs.preview'), ['service_id' => $service->id]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->headers->get('Content-Disposition'))->toContain('fuec-preview.pdf');
    expect(Fuec::count())->toBe($fuecCountBefore);
    // Response body should start with the PDF magic number.
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

test('preview runs the pre-generation validation gauntlet', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();
    // Break the gauntlet: mark the contract inactive.
    $service->contract->update(['active' => false]);

    post(route('fuecs.preview'), ['service_id' => $service->id])
        ->assertSessionHasErrors();
});

test('preview requires GENERATE_FUEC permission', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::OPERATOR->value);
    actingAs($user);

    $service = fuecReadyService();

    post(route('fuecs.preview'), ['service_id' => $service->id])
        ->assertForbidden();
});

test('cancel rejects a short reason', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $fuec = Fuec::factory()->create([
        'fuec_number_range_id' => FuecNumberRange::factory()->create()->id,
        'status' => FuecStatus::Active,
    ]);

    post(route('fuecs.cancel', $fuec), ['reason' => 'corto'])
        ->assertSessionHasErrors('reason');
});

test('cancel on already cancelled FUEC is rejected', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $fuec = Fuec::factory()->cancelled()->create([
        'fuec_number_range_id' => FuecNumberRange::factory()->create()->id,
    ]);

    post(route('fuecs.cancel', $fuec), ['reason' => 'No se puede anular dos veces.'])
        ->assertSessionHasErrors('status');
});

test('candidateServices returns closed services without an active FUEC', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN->value);
    actingAs($admin);

    $service = fuecReadyService();

    $response = get(route('fuecs.candidate-services'));
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json');

    $payload = $response->json();
    $ids = array_column($payload, 'id');
    expect($ids)->toContain($service->id);
});
