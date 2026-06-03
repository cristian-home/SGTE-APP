<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'show'])->middleware(['auth', 'verified', 'can:dashboard.view'])->name('dashboard');

// Public FUEC verification endpoint (REQ-007 AC#4) — scanned from the
// QR embedded on the PDF. No auth; gated only by the feature flag.
Route::get('fuec/verify/{uuid}', [App\Http\Controllers\FuecVerifyController::class, 'show'])
    ->middleware('fuec.enabled')
    ->name('fuec.verify');

require __DIR__.'/settings.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('about', [App\Http\Controllers\AboutController::class, 'show'])->name('about');

    // Driver dashboard
    Route::get('driver', [App\Http\Controllers\DriverDashboardController::class, 'index'])->name('driver.dashboard');
    Route::post('driver/services/{service}/confirm-start', [App\Http\Controllers\DriverDashboardController::class, 'confirmStart'])->name('driver.confirm-start');
    Route::post('driver/services/{service}/confirm-end', [App\Http\Controllers\DriverDashboardController::class, 'confirmEnd'])->name('driver.confirm-end');
    Route::post('driver/services/{service}/decline', [App\Http\Controllers\DriverDashboardController::class, 'decline'])->name('driver.decline');

    Route::get('gantt', [App\Http\Controllers\GanttController::class, 'index'])
        ->middleware('can:'.App\Enums\Permission::VIEW_SERVICES->value)
        ->name('gantt.index');
    Route::get('day-summary/export', [App\Http\Controllers\DaySummaryController::class, 'export'])
        ->middleware('can:'.App\Enums\Permission::VIEW_DAY_SUMMARY->value)
        ->name('day-summary.export');
    Route::get('day-summary', [App\Http\Controllers\DaySummaryController::class, 'index'])
        ->middleware('can:'.App\Enums\Permission::VIEW_DAY_SUMMARY->value)
        ->name('day-summary.index');

    // Legacy create/edit URLs — the standalone create/edit pages were
    // replaced by in-page modals. These literal routes MUST be registered
    // before the Route::resource calls below so a single-segment path like
    // `/{resource}/create` or `/{resource}/edit` resolves here instead of
    // falling through to the resource `show` route — route-model binding
    // on a non-numeric id would 500 on Postgres. A bookmarked legacy URL
    // lands on the index, where the create/edit modal now lives.
    foreach ([
        'document-types', 'eps', 'pension-funds', 'severance-funds',
        'third-parties', 'drivers', 'vehicles', 'contracts', 'invoices',
        'incident-types', 'vehicle-types',
    ] as $modalResource) {
        Route::get($modalResource.'/create', fn () => redirect()->route($modalResource.'.index'));
        Route::get($modalResource.'/edit', fn () => redirect()->route($modalResource.'.index'));
        Route::get($modalResource.'/{record}/edit', fn () => redirect()->route($modalResource.'.index'));
    }

    // Static catalogs — single MANAGE_CATALOGS permission gates all four
    // resources end-to-end (view/create/update/delete).
    Route::resource('document-types', App\Http\Controllers\DocumentTypeController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::MANAGE_CATALOGS->value);
    Route::resource('eps', App\Http\Controllers\EpsController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::MANAGE_CATALOGS->value);
    Route::resource('pension-funds', App\Http\Controllers\PensionFundController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::MANAGE_CATALOGS->value);
    Route::resource('severance-funds', App\Http\Controllers\SeveranceFundController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::MANAGE_CATALOGS->value);
    // Master-data resources — route-level `can:*.view` is the cheap
    // baseline gate. Each CREATE/UPDATE/DELETE action re-checks its
    // specific permission through FormRequest::authorize() (layer 3 in
    // ADR-005). In this role matrix every role that holds a mutation
    // permission also holds the corresponding view permission, so the
    // resource-wide `can:view` does not accidentally block legitimate
    // mutation traffic. See ADR-005 §Layer 2.
    Route::resource('third-parties', App\Http\Controllers\ThirdPartyController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_THIRD_PARTIES->value);
    Route::post('drivers/{driver}/invite-account', [App\Http\Controllers\DriverController::class, 'inviteAccount'])
        ->middleware('can:'.App\Enums\Permission::UPDATE_DRIVERS->value)
        ->name('drivers.invite-account');
    Route::post('drivers/{driver}/resend-invitation', [App\Http\Controllers\DriverController::class, 'resendInvitation'])
        ->middleware('can:'.App\Enums\Permission::UPDATE_DRIVERS->value)
        ->name('drivers.resend-invitation');
    Route::resource('drivers', App\Http\Controllers\DriverController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_DRIVERS->value);
    Route::resource('vehicles', App\Http\Controllers\VehicleController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_VEHICLES->value);
    Route::resource('contracts', App\Http\Controllers\ContractController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_CONTRACTS->value);
    Route::post('invoices/{invoice}/mark-paid', [App\Http\Controllers\InvoiceController::class, 'markPaid'])
        ->middleware('can:'.App\Enums\Permission::UPDATE_INVOICES->value)
        ->name('invoices.mark-paid');
    Route::get('invoices/{invoice}/pdf', [App\Http\Controllers\InvoiceController::class, 'pdf'])->middleware('can:'.App\Enums\Permission::VIEW_INVOICES->value)->name('invoices.pdf');
    Route::post('invoices/{invoice}/services', [App\Http\Controllers\InvoiceController::class, 'attachServices'])->middleware('can:'.App\Enums\Permission::ASSIGN_SERVICES_TO_INVOICES->value)->name('invoices.services.attach');
    Route::delete('invoices/{invoice}/services/{service}', [App\Http\Controllers\InvoiceController::class, 'detachService'])->middleware('can:'.App\Enums\Permission::ASSIGN_SERVICES_TO_INVOICES->value)->name('invoices.services.detach');
    Route::post('invoices/{invoice}/recompute-total', [App\Http\Controllers\InvoiceController::class, 'recomputeTotal'])->middleware('can:'.App\Enums\Permission::ASSIGN_SERVICES_TO_INVOICES->value)->name('invoices.recompute-total');
    Route::get('invoices-eligible-services', [App\Http\Controllers\InvoiceController::class, 'eligibleServices'])
        ->middleware('can:'.App\Enums\Permission::ASSIGN_SERVICES_TO_INVOICES->value)
        ->name('invoices.eligible-services');
    Route::get('invoices/{invoice}/attached-services', [App\Http\Controllers\InvoiceController::class, 'attachedServices'])
        ->middleware('can:'.App\Enums\Permission::VIEW_INVOICES->value)
        ->name('invoices.attached-services');
    Route::resource('invoices', App\Http\Controllers\InvoiceController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_INVOICES->value);
    Route::get('day-statuses/{year}/{month}', [App\Http\Controllers\DayStatusController::class, 'calendarMonth'])
        ->middleware('can:'.App\Enums\Permission::VIEW_DAY_SUMMARY->value)
        ->name('day-statuses.calendar-month')
        ->where(['year' => '20[2-9][0-9]', 'month' => '[1-9]|1[0-2]']);
    Route::get('day-statuses/{year}', [App\Http\Controllers\DayStatusController::class, 'calendar'])
        ->middleware('can:'.App\Enums\Permission::VIEW_DAY_SUMMARY->value)
        ->name('day-statuses.calendar')
        ->where('year', '20[2-9][0-9]');
    Route::post('day-statuses/{day_status}/execute', [App\Http\Controllers\DayStatusController::class, 'execute'])
        ->middleware('can:'.App\Enums\Permission::EXECUTE_DAY->value)
        ->name('day-statuses.execute');
    Route::resource('day-statuses', App\Http\Controllers\DayStatusController::class)
        ->middleware('can:'.App\Enums\Permission::VIEW_DAY_SUMMARY->value);
    Route::get('services-eta', [App\Http\Controllers\ServiceController::class, 'eta'])
        ->middleware('can:'.App\Enums\Permission::CREATE_SERVICES->value)
        ->name('services.eta');
    Route::resource('services', App\Http\Controllers\ServiceController::class)
        ->middleware('can:'.App\Enums\Permission::VIEW_SERVICES->value);
    Route::resource('incident-types', App\Http\Controllers\IncidentTypeController::class)
        ->except(['create', 'edit'])
        ->middleware('can:'.App\Enums\Permission::VIEW_INCIDENT_TYPES->value);
    Route::resource('vehicle-types', App\Http\Controllers\VehicleTypeController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware('can:'.App\Enums\Permission::VIEW_VEHICLE_TYPES->value);
    // Service incidents — intentionally NOT gated at the route level.
    // Drivers have CREATE_INCIDENTS but no VIEW_INCIDENTS (they file
    // incidents on their own services from the driver portal), so a
    // resource-wide `can:incidents.view` middleware would block a
    // legitimate create flow. Per-action gating lives in
    // ServiceIncidentController + ServiceIncidentStoreRequest /
    // ServiceIncidentUpdateRequest, each of which calls
    // `Gate::authorize()` with the action-specific permission
    // (VIEW_INCIDENTS / CREATE_INCIDENTS / UPDATE_INCIDENTS /
    // DELETE_INCIDENTS). See ADR-005 §Layer 2.
    Route::resource('service-incidents', App\Http\Controllers\ServiceIncidentController::class);
    // FUEC module (REQ-007) — gated behind the sgte.fuec_enabled feature
    // flag so the whole group 404s when the module is disabled.
    Route::middleware('fuec.enabled')->group(function (): void {
        Route::get('fuecs/candidate-services', [App\Http\Controllers\FuecController::class, 'candidateServices'])
            ->middleware('can:'.App\Enums\Permission::GENERATE_FUEC->value)
            ->name('fuecs.candidate-services');
        Route::post('fuecs/preview', [App\Http\Controllers\FuecController::class, 'preview'])
            ->middleware('can:'.App\Enums\Permission::GENERATE_FUEC->value)
            ->name('fuecs.preview');
        Route::get('fuecs/{fuec}/pdf', [App\Http\Controllers\FuecController::class, 'pdf'])
            ->middleware('can:'.App\Enums\Permission::VIEW_FUEC->value)
            ->name('fuecs.pdf');
        Route::post('fuecs/{fuec}/cancel', [App\Http\Controllers\FuecController::class, 'cancel'])
            ->middleware('can:'.App\Enums\Permission::GENERATE_FUEC->value)
            ->name('fuecs.cancel');
        Route::resource('fuecs', App\Http\Controllers\FuecController::class)
            ->only(['index', 'create', 'store', 'show']);
        Route::resource('fuec-number-ranges', App\Http\Controllers\FuecNumberRangeController::class)
            ->middleware('can:'.App\Enums\Permission::MANAGE_FUEC_NUMBER_RANGES->value);
    });

    // GPS / Vehicle Locations (REQ-010) — gated behind the sgte.gps_enabled
    // feature flag so the group 404s when the module is disabled.
    Route::middleware('gps.enabled')->group(function (): void {
        Route::get('gps/map', [App\Http\Controllers\VehicleLocationMapController::class, 'index'])
            ->middleware('can:'.App\Enums\Permission::VIEW_VEHICLE_LOCATIONS->value)
            ->name('gps.map');
        Route::resource('vehicle-locations', App\Http\Controllers\VehicleLocationController::class)
            ->middleware('can:'.App\Enums\Permission::VIEW_VEHICLE_LOCATIONS->value);
        Route::post('driver/services/{service}/location', [App\Http\Controllers\DriverLocationController::class, 'store'])
            ->middleware('can:'.App\Enums\Permission::REGISTER_VEHICLE_LOCATION->value)
            ->name('driver.location.store');
    });

    // Administración (admin only)
    Route::middleware('can:'.App\Enums\Permission::VIEW_USERS->value)->group(function (): void {
        Route::get('users', [App\Http\Controllers\UserController::class, 'index'])->name('users.index');
        Route::post('users', [App\Http\Controllers\UserController::class, 'store'])->name('users.store');
        Route::put('users/{user}', [App\Http\Controllers\UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [App\Http\Controllers\UserController::class, 'destroy'])->name('users.destroy');
        Route::patch('users/{user}/active', [App\Http\Controllers\UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::post('users/{user}/reset-password', [App\Http\Controllers\UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('roles', [App\Http\Controllers\RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/{role}', [App\Http\Controllers\RoleController::class, 'show'])->name('roles.show');
        Route::put('roles/{role}', [App\Http\Controllers\RoleController::class, 'update'])->name('roles.update');

        Route::get('permissions', [App\Http\Controllers\PermissionController::class, 'index'])->name('permissions.index');
    });
    Route::get('audit-log', [App\Http\Controllers\AuditLogController::class, 'index'])
        ->middleware('can:'.App\Enums\Permission::VIEW_AUDIT_LOG->value)
        ->name('audit-log.index');

    // Admin > Data Imports (super admin only via MANAGE_DATA_IMPORTS).
    // Templates and reference catalogs are gated by the same permission so
    // a non-super-admin cannot exfiltrate the catalog list either.
    Route::middleware('can:'.App\Enums\Permission::MANAGE_DATA_IMPORTS->value)
        ->prefix('admin/imports')
        ->name('admin.imports.')
        ->group(function (): void {
            Route::get('/', [App\Http\Controllers\DataImportController::class, 'index'])->name('index');
            Route::get('/create', [App\Http\Controllers\DataImportController::class, 'create'])->name('create');
            Route::post('/', [App\Http\Controllers\DataImportController::class, 'store'])->name('store');

            Route::get('/templates/{type}', [App\Http\Controllers\DataImportTemplateController::class, 'show'])
                ->where('type', 'users|third-parties|drivers|vehicles')
                ->name('templates.show');
            Route::get('/reference/{catalog}', [App\Http\Controllers\DataImportReferenceController::class, 'show'])
                ->where('catalog', 'eps|pension-funds|severance-funds|municipalities|departments|document-types|incident-types')
                ->name('reference.show');

            Route::get('/{import}', [App\Http\Controllers\DataImportController::class, 'show'])->name('show');
            Route::delete('/{import}/files', [App\Http\Controllers\DataImportController::class, 'purge'])->name('purge');
            Route::get('/{import}/download/source', [App\Http\Controllers\DataImportController::class, 'downloadSource'])->name('download.source');
            Route::get('/{import}/download/errors', [App\Http\Controllers\DataImportController::class, 'downloadErrors'])->name('download.errors');
        });
});
