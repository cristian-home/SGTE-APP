<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Contract;
use App\Models\DocumentType;
use App\Models\Driver;
use App\Models\Eps;
use App\Models\IncidentType;
use App\Models\Invoice;
use App\Models\PensionFund;
use App\Models\ServiceIncident;
use App\Models\SeveranceFund;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;

/**
 * Cross-cutting regression guard: every DataTable ships a search box that
 * sends `filter[search]`. Spatie QueryBuilder throws `InvalidFilterQuery`
 * (HTTP 400) for any filter not declared in `allowedFilters`, so a missing
 * `search` filter silently broke the search box on every index page. These
 * tests prove the filter is wired and actually narrows results.
 *
 * Single-word search terms are used on purpose: tests run on SQLite, whose
 * fallback is a plain LIKE (no trigram/word_similarity), and composite name
 * columns with empty middle fields produce double spaces that a multi-word
 * LIKE would miss.
 */
beforeEach(function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $this->actingAs($user);
});

test('third-parties search matches company name', function (): void {
    ThirdParty::factory()->create(['company_name' => 'BuscarClinicaUnica', 'is_natural_person' => false]);
    ThirdParty::factory()->create(['company_name' => 'Otra Empresa', 'is_natural_person' => false]);

    $response = get(route('third-parties.index', ['filter[search]' => 'BuscarClinicaUnica']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('thirdParties.data', 1)
        ->where('thirdParties.data.0.company_name', 'BuscarClinicaUnica'));
});

test('third-parties search matches identification number', function (): void {
    ThirdParty::factory()->create(['identification_number' => '99887766XYZ']);
    ThirdParty::factory()->create(['identification_number' => '11112222ABC']);

    $response = get(route('third-parties.index', ['filter[search]' => '99887766XYZ']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('thirdParties.data', 1));
});

test('third-parties search matches a natural person name and is case insensitive', function (): void {
    ThirdParty::factory()->create([
        'is_natural_person' => true,
        'first_name' => 'Ricardonombreunico',
        'company_name' => null,
    ]);
    ThirdParty::factory()->create(['is_natural_person' => true, 'first_name' => 'Otro', 'company_name' => null]);

    $response = get(route('third-parties.index', ['filter[search]' => 'ricardonombreunico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('thirdParties.data', 1));
});

test('vehicles search matches the plate', function (): void {
    Vehicle::factory()->create(['plate' => 'SRCHAA01']);
    Vehicle::factory()->create(['plate' => 'OTHER02']);

    $response = get(route('vehicles.index', ['filter[search]' => 'SRCHAA01']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('vehicles.data', 1));
});

test('drivers search matches a name fragment', function (): void {
    Driver::factory()->create(['first_name' => 'Carlosnombreunico']);
    Driver::factory()->create(['first_name' => 'Distinto']);

    $response = get(route('drivers.index', ['filter[search]' => 'carlosnombreunico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('drivers.data', 1));
});

test('contracts search matches the contract number', function (): void {
    Contract::factory()->create(['contract_number' => 'CONT-BUSCAR-001']);
    Contract::factory()->create(['contract_number' => 'CONT-OTRO-002']);

    $response = get(route('contracts.index', ['filter[search]' => 'CONT-BUSCAR-001']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('contracts.data', 1));
});

test('contracts search matches the related third party company name', function (): void {
    $client = ThirdParty::factory()->create(['company_name' => 'TerceroDelContratoXYZ', 'is_natural_person' => false]);
    Contract::factory()->create(['third_party_id' => $client->id]);
    Contract::factory()->create();

    $response = get(route('contracts.index', ['filter[search]' => 'TerceroDelContratoXYZ']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('contracts.data', 1));
});

test('invoices search matches the invoice number', function (): void {
    Invoice::factory()->create(['invoice_number' => 'FAC-BUSCAR-9001']);
    Invoice::factory()->create(['invoice_number' => 'FAC-OTRA-9002']);

    $response = get(route('invoices.index', ['filter[search]' => 'FAC-BUSCAR-9001']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1));
});

test('service-incidents search matches the description', function (): void {
    ServiceIncident::factory()->create(['description' => 'Llantapinchadaunica en la via']);
    ServiceIncident::factory()->create(['description' => 'Otro evento distinto']);

    $response = get(route('service-incidents.index', ['filter[search]' => 'Llantapinchadaunica']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('serviceIncidents.data', 1));
});

test('vehicle-locations search matches the related vehicle plate', function (): void {
    $vehicle = Vehicle::factory()->create(['plate' => 'GPSBUSCAR1']);
    VehicleLocation::factory()->create(['vehicle_id' => $vehicle->id]);
    VehicleLocation::factory()->create();

    $response = get(route('vehicle-locations.index', ['filter[search]' => 'GPSBUSCAR1']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('vehicleLocations.data', 1));
});

test('audit-log search matches the activity description', function (): void {
    activity()->log('BuscarEventoAuditoriaUnico');
    activity()->log('Otro evento de auditoria');

    $response = get(route('audit-log.index', ['filter[search]' => 'BuscarEventoAuditoriaUnico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('activities.data', 1));
});

test('eps catalog search matches a unique name', function (): void {
    Eps::factory()->create(['name' => 'EpsBuscarUnica']);

    $response = get(route('eps.index', ['filter[search]' => 'EpsBuscarUnica']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('eps', 1));
});

test('pension-funds catalog search matches a unique name', function (): void {
    PensionFund::factory()->create(['name' => 'FondoPensionBuscarUnico']);

    $response = get(route('pension-funds.index', ['filter[search]' => 'FondoPensionBuscarUnico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('pensionFunds', 1));
});

test('severance-funds catalog search matches a unique name', function (): void {
    SeveranceFund::factory()->create(['name' => 'CesantiasBuscarUnico']);

    $response = get(route('severance-funds.index', ['filter[search]' => 'CesantiasBuscarUnico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('severanceFunds', 1));
});

test('document-types catalog search matches a unique name', function (): void {
    DocumentType::factory()->create(['name' => 'TipoDocBuscarUnico']);

    $response = get(route('document-types.index', ['filter[search]' => 'TipoDocBuscarUnico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('documentTypes', 1));
});

test('incident-types catalog search matches a unique name', function (): void {
    IncidentType::factory()->create(['name' => 'TipoNovedadBuscarUnico']);

    $response = get(route('incident-types.index', ['filter[search]' => 'TipoNovedadBuscarUnico']));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->has('incidentTypes', 1));
});
