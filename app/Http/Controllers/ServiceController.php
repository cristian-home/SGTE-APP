<?php

namespace App\Http\Controllers;

use App\Enums\DayStatusEnum;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VehicleStatus;
use App\Http\Requests\ServiceStoreRequest;
use App\Http\Requests\ServiceUpdateRequest;
use App\Models\Contract;
use App\Models\DayStatus;
use App\Models\Driver;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use App\Notifications\ServiceAssignedNotification;
use App\Support\FacetCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ServiceController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_SERVICES->value);

        $services = QueryBuilder::for(Service::class)
            ->with(['contract', 'vehicle', 'driver'])
            ->allowedIncludes(['invoice'])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts([
                'service_number',
                'service_date_local',
                'unit_value',
                'service_status',
                'planned_start_at',
                'planned_end_at',
                'actual_start_at',
                'actual_end_at',
                'created_at',
            ])
            // Newest services first by default (no sort param). `-id` is the
            // deterministic tiebreaker for rows created in the same instant.
            ->defaultSort('-created_at', '-id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($services);
        }

        return Inertia::render('services/index', [
            'services' => $services,
            'facetCounts' => FacetCounts::for(
                Service::class,
                $this->allowedFilters(),
                $request,
                [
                    'contract_id' => 'contract_id',
                    'driver_id' => 'driver_id',
                    'vehicle_id' => 'vehicle_id',
                    'origin_municipality_id' => 'origin_municipality_id',
                    'destination_municipality_id' => 'destination_municipality_id',
                ],
            ),
            ...$this->indexFilterOptions(),
        ]);
    }

    /**
     * QueryBuilder filters shared by index() and the facet-count helper.
     *
     * @return array<int, mixed>
     */
    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
            AllowedFilter::partial('service_number'),
            AllowedFilter::callback('service_date', fn (Builder $query, $value) => $query->whereDate('service_date_local', $value)),
            AllowedFilter::exact('origin_municipality_id'),
            AllowedFilter::exact('destination_municipality_id'),
            AllowedFilter::exact('service_status'),
            AllowedFilter::exact('payment_method'),
            AllowedFilter::exact('contract_id'),
            AllowedFilter::exact('driver_id'),
            AllowedFilter::exact('vehicle_id'),
            AllowedFilter::callback('date_from', fn (Builder $query, $value) => $query->whereDate('service_date_local', '>=', $value)),
            AllowedFilter::callback('date_to', fn (Builder $query, $value) => $query->whereDate('service_date_local', '<=', $value)),
            ...$this->dateRangeFilters('planned_start', 'planned_start_at'),
            ...$this->dateRangeFilters('planned_end', 'planned_end_at'),
            ...$this->dateRangeFilters('actual_start', 'actual_start_at'),
            ...$this->dateRangeFilters('actual_end', 'actual_end_at'),
            ...$this->dateRangeFilters('created', 'created_at'),
        ];
    }

    /**
     * Estimate driving duration between two coordinates for the
     * `/services/create` Horarios block. Curated cache hit avoids any
     * network call; otherwise falls back to Google Routes (cached for
     * 7 days) and returns null when the API can't deliver.
     */
    public function eta(Request $request): JsonResponse
    {
        Gate::authorize(Permission::CREATE_SERVICES->value);

        $validated = $request->validate([
            'origin_coordinates' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'destination_coordinates' => ['required', 'string', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
        ]);

        $origin = trim((string) $validated['origin_coordinates']);
        $destination = trim((string) $validated['destination_coordinates']);

        $curated = \App\Support\CuratedRoutes::forCoords($origin, $destination);
        if ($curated !== null) {
            return response()->json([
                'eta_minutes' => (int) round($curated['duration_s'] / 60),
                'source' => 'curated',
            ]);
        }

        $cacheKey = 'eta:'.$origin.'>'.$destination;
        $cache = \Illuminate\Support\Facades\Cache::store();

        $live = $cache->get($cacheKey);
        if (! is_array($live)) {
            [$originLat, $originLng] = array_map('floatval', explode(',', $origin));
            [$destLat, $destLng] = array_map('floatval', explode(',', $destination));

            /** @var \App\Services\Google\RoutesClient $client */
            $client = app(\App\Services\Google\RoutesClient::class);
            $live = $client->driving($originLng, $originLat, $destLng, $destLat);

            if (is_array($live) && isset($live['duration_s'])) {
                $cache->put($cacheKey, $live, now()->addDays(7));
            }
        }

        if (! is_array($live) || ! isset($live['duration_s'])) {
            return response()->json([
                'eta_minutes' => null,
                'source' => null,
            ]);
        }

        return response()->json([
            'eta_minutes' => (int) round(((int) $live['duration_s']) / 60),
            'source' => 'live',
        ]);
    }

    /**
     * Option data used by the /services filter bar — contract, driver,
     * vehicle, and municipality comboboxes. Scoped to "active" or
     * in-use rows so the picker stays useful as the catalog grows.
     *
     * @return array<string, mixed>
     */
    private function indexFilterOptions(): array
    {
        return [
            'filterContracts' => Contract::query()
                ->where('active', true)
                ->with('thirdParty:id,identification_number,first_name,first_lastname,company_name,is_natural_person')
                ->orderBy('contract_number')
                ->get(['id', 'contract_number', 'third_party_id']),
            'filterDrivers' => Driver::query()
                ->where('active', true)
                ->orderBy('first_lastname')
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'first_lastname', 'identification_number']),
            'filterVehicles' => Vehicle::query()
                ->where('status', VehicleStatus::Active)
                ->orderBy('plate')
                ->get(['id', 'plate', 'is_third_party']),
            'filterMunicipalities' => Municipality::query()
                ->with('department:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id']),
        ];
    }

    /**
     * Build a `<prefix>_from` / `<prefix>_to` pair of day-granularity range
     * filters over a datetime column, mirroring the existing
     * `date_from` / `date_to` pattern on `service_date_local`.
     *
     * @return array<int, AllowedFilter>
     */
    private function dateRangeFilters(string $prefix, string $column): array
    {
        return [
            AllowedFilter::callback("{$prefix}_from", function (Builder $query, $value) use ($column): void {
                $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                if ($value !== '') {
                    $query->whereDate($column, '>=', $value);
                }
            }),
            AllowedFilter::callback("{$prefix}_to", function (Builder $query, $value) use ($column): void {
                $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                if ($value !== '') {
                    $query->whereDate($column, '<=', $value);
                }
            }),
        ];
    }

    public function create(Request $request): Response
    {
        Gate::authorize(Permission::CREATE_SERVICES->value);

        $prefill = array_filter($request->only(['vehicle_id', 'planned_start_time', 'service_date']));

        // BUG-10 — surface the executed days so the create form can:
        //   1. Block (or warn) non-Admin roles from picking an EJECUTADO day.
        //   2. Reveal the `justification` field for Admin / Super Admin per
        //      the BUG-03 backend exception path.
        // Limited to the rolling [-180d, +30d] window relative to operation
        // TZ today to keep the payload tight.
        $operationTz = (string) config('app.operation_tz', 'America/Bogota');
        $today = Carbon::now($operationTz);
        $executedDates = DayStatus::query()
            ->where('status', DayStatusEnum::Executed)
            ->whereDate('date', '>=', $today->copy()->subDays(180))
            ->whereDate('date', '<=', $today->copy()->addDays(30))
            ->pluck('date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->values();

        return Inertia::render('services/create', [
            ...$this->formReferenceData(),
            'reservedServiceNumber' => $this->reserveServiceNumber($request),
            'prefill' => ! empty($prefill) ? $prefill : null,
            'executedDates' => $executedDates,
            'canBypassExecutedDay' => auth()->user()->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]),
            // Forwarded into the cascade of dialogs reachable from this
            // page: ContractCreateDialog (uses thirdParties) and the
            // nested ThirdPartyCreateDialog (uses documentTypes +
            // municipalities). Loaded eagerly because the cascade is the
            // expected path for new corporate clients; lazy-loading would
            // require an extra round-trip on first open.
            'thirdParties' => ThirdParty::query()
                ->where('is_customer', true)
                ->with('documentType:id,code,name')
                ->orderBy('company_name')
                ->orderBy('first_lastname')
                ->get([
                    'id',
                    'document_type_id',
                    'identification_number',
                    'is_natural_person',
                    'first_name',
                    'first_lastname',
                    'company_name',
                    'is_customer',
                    'is_provider',
                ]),
            'documentTypes' => \App\Models\DocumentType::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Reserve (or reuse) the consecutive shown read-only on the create form.
     *
     * A fresh reservation durably advances the per-year counter, so we cache
     * it in the session and reuse it across reloads of the create page as long
     * as it hasn't been consumed yet (no Service persisted with it). That way a
     * simple refresh doesn't burn a gap; only opening the form once and
     * abandoning it does.
     */
    private function reserveServiceNumber(Request $request): string
    {
        $reserved = $request->session()->get('service_number_reservation');

        if (is_string($reserved) && $reserved !== ''
            && ! Service::withTrashed()->where('service_number', $reserved)->exists()) {
            return $reserved;
        }

        $reserved = Service::reserveNextNumber();
        $request->session()->put('service_number_reservation', $reserved);

        return $reserved;
    }

    public function store(ServiceStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_SERVICES->value);

        // Q5 / bug-log:BUG-01 — if the chosen contract does not cover the
        // service date AND the operator opted into auto-generic creation
        // (via the `create_generic_contract` flag), bridge by creating a
        // GEN-NNNN-YYYY contract for the original contract's tercero and
        // substituting it. Otherwise fall through to standard creation —
        // ServiceStoreRequest's validateContractCoversDate already rejects
        // the out-of-window case for callers that didn't opt in.
        $serviceData = $request->validated();
        $contractId = $this->maybeBridgeToGenericContract($request, $serviceData);
        if ($contractId !== null) {
            $serviceData['contract_id'] = $contractId;
        }

        // Strip request-only fields that don't map to a Service column.
        // `manual_entry_justification` IS persisted on the row (see
        // create_services_table migration) so it stays in $serviceData.
        unset(
            $serviceData['justification'],
            $serviceData['create_generic_contract'],
        );

        // Persist the reserved consecutive (the read-only value the form
        // showed). Retry on the rare UNIQUE collision — e.g. two tabs sharing
        // the same session reservation — by re-reserving a fresh number. The
        // UNIQUE constraint on services.service_number is the final guard.
        $reserved = (string) ($serviceData['service_number'] ?? '');

        $service = null;
        $attempts = 0;
        while ($attempts < 3) {
            try {
                $serviceData['service_number'] = $reserved !== ''
                    ? $reserved
                    : Service::reserveNextNumber();
                $service = Service::create($serviceData);
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempts++;
                $reserved = '';
                if ($attempts >= 3) {
                    throw $e;
                }
            }
        }

        $request->session()->forget('service_number_reservation');

        // REQ-009: tag retroactive closed entries so /audit-log can
        // filter them apart from services closed via the driver
        // workflow. Only fires when the gate in ServiceStoreRequest
        // passed — i.e. service_date < today && status = closed &&
        // a justification was supplied.
        $justification = trim((string) $request->input('manual_entry_justification'));
        if ($justification !== '') {
            activity()
                ->performedOn($service)
                ->causedBy($request->user())
                ->withProperties([
                    'source' => 'retroactive_entry',
                    'manual_entry_justification' => $justification,
                    'service_date_local' => $service->service_date_local instanceof \DateTimeInterface
                        ? $service->service_date_local->format('Y-m-d')
                        : (string) $service->service_date_local,
                    'timezone' => (string) $service->timezone,
                ])
                ->log('Registro retroactivo de servicio cerrado');
        }

        // Q4 / bug-log:BUG-03 — Admin / Super Admin can late-add to an
        // EJECUTADO day with a justification. Tag the activity log so the
        // audit trail surfaces this as distinct from normal entries.
        $lateAddJustification = trim((string) $request->input('justification'));
        if ($lateAddJustification !== '') {
            $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();
            if ($dayStatus?->status === DayStatusEnum::Executed) {
                activity()
                    ->performedOn($service)
                    ->causedBy($request->user())
                    ->withProperties([
                        'late_added_on_executed_day' => true,
                        'justification' => $lateAddJustification,
                    ])
                    ->log('Servicio agregado a día ejecutado');
            }
        }

        if ($service->driver_id) {
            $driverUser = Driver::find($service->driver_id)?->user;
            if ($driverUser) {
                $service->load('vehicle');
                $driverUser->notify(new ServiceAssignedNotification($service));
            }
        }

        return redirect()->route('services.index');
    }

    public function show(Request $request, Service $service): Response
    {
        Gate::authorize(Permission::VIEW_SERVICES->value);

        $service->load([
            'contract.thirdParty',
            'vehicle.thirdParty',
            'driver',
            'originMunicipality.department',
            'destinationMunicipality.department',
            'invoice',
            'serviceIncidents.incidentType',
            'serviceIncidents.registrar',
        ]);
        $service->loadCount('serviceIncidents');

        $dayStatus = DayStatus::with('executor')->whereDate('date', $service->service_date_local)->first();

        // Dedicated payload for the Novedades card — limits to the last
        // 5 incidents and pins the ordering. The full serviceIncidents
        // relation on $service still carries everything for any card
        // that needs it.
        $recentIncidents = \App\Models\ServiceIncident::query()
            ->where('service_id', $service->id)
            ->with([
                'incidentType:id,name,severity',
                'registrar:id,name',
            ])
            ->orderByDesc('reported_at')
            ->limit(5)
            ->get([
                'id',
                'service_id',
                'incident_type_id',
                'registrar_id',
                'reported_at',
                'is_driver_report',
                'affects_billing',
            ]);

        // Billing-affecting incidents projected to the shape the
        // <IncidentsBillingBreakdown> table consumes. The Service model
        // already eager-loads `serviceIncidents.incidentType` above, so
        // this is just a filtered projection — no extra query.
        $billingIncidents = $service->serviceIncidents
            ->where('affects_billing', true)
            ->values()
            ->map(fn ($incident) => [
                'id' => $incident->id,
                'additional_value' => $incident->additional_value,
                'description' => $incident->description,
                'reported_at' => $incident->reported_at?->toIso8601String(),
                'incident_type' => $incident->incidentType
                    ? ['id' => $incident->incidentType->id, 'name' => $incident->incidentType->name]
                    : null,
            ]);

        return Inertia::render('services/show', [
            'service' => $service,
            'dayStatus' => $dayStatus,
            'recentIncidents' => $recentIncidents,
            'billingIncidents' => $billingIncidents,
        ]);
    }

    public function edit(Request $request, Service $service): Response
    {
        if (Gate::none([Permission::UPDATE_PROJECTED_SERVICES->value, Permission::UPDATE_EXECUTED_SERVICES->value])) {
            abort(403);
        }

        $service->load([
            'contract',
            'vehicle.thirdParty',
            'driver',
            'originMunicipality.department',
            'destinationMunicipality.department',
        ]);
        $service->loadCount('serviceIncidents');

        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        return Inertia::render('services/edit', [
            'service' => $service,
            'dayStatus' => $dayStatus,
            'canEditExecuted' => auth()->user()->can(Permission::UPDATE_EXECUTED_SERVICES->value),
            'isAdmin' => auth()->user()->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN]),
            ...$this->formReferenceData(),
        ]);
    }

    public function update(ServiceUpdateRequest $request, Service $service): RedirectResponse
    {
        if (Gate::none([Permission::UPDATE_PROJECTED_SERVICES->value, Permission::UPDATE_EXECUTED_SERVICES->value])) {
            abort(403);
        }

        $validated = $request->validated();
        $justification = $validated['justification'] ?? null;
        unset($validated['justification']);

        // REQ-009 reopen invariant: capture before-state so the
        // activity-log entry can name which actual_*_time fields were
        // cleared or set during the status transition.
        $statusBefore = $service->service_status instanceof \App\Enums\ServiceStatus
            ? $service->service_status->value
            : (string) $service->service_status;
        $actualStartBefore = $service->actual_start_at;
        $actualEndBefore = $service->actual_end_at;

        $service->update($validated);
        $service->refresh();

        $statusAfter = $service->service_status instanceof \App\Enums\ServiceStatus
            ? $service->service_status->value
            : (string) $service->service_status;

        if ($statusBefore !== $statusAfter) {
            $clearedFields = [];
            $setFields = [];

            if ($actualStartBefore !== null && $service->actual_start_at === null) {
                $clearedFields[] = 'actual_start_at';
            }
            if ($actualEndBefore !== null && $service->actual_end_at === null) {
                $clearedFields[] = 'actual_end_at';
            }
            if ($actualStartBefore === null && $service->actual_start_at !== null) {
                $setFields[] = 'actual_start_at';
            }
            if ($actualEndBefore === null && $service->actual_end_at !== null) {
                $setFields[] = 'actual_end_at';
            }

            activity()
                ->performedOn($service)
                ->causedBy(auth()->user())
                ->withProperties([
                    'status_from' => $statusBefore,
                    'status_to' => $statusAfter,
                    'cleared_fields' => $clearedFields,
                    'set_fields' => $setFields,
                ])
                ->log('Servicio cambió de estado');
        }

        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        if ($dayStatus?->status === DayStatusEnum::Executed && $justification) {
            activity()
                ->performedOn($service)
                ->causedBy(auth()->user())
                ->withProperties([
                    'justification' => $justification,
                    'edited_on_executed_day' => true,
                ])
                ->log('Servicio editado en día ejecutado');
        }

        return redirect()->route('services.index');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_SERVICES->value);

        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        if ($dayStatus?->status === DayStatusEnum::Executed) {
            if (! auth()->user()->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN])) {
                abort(403);
            }
        }

        $service->delete();

        return redirect()->route('services.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formReferenceData(): array
    {
        return [
            'vehicles' => Vehicle::query()
                ->where('status', VehicleStatus::Active)
                ->with([
                    'vehicleType:id,code,name',
                    'thirdParty:id,identification_number,first_name,first_lastname,company_name,is_natural_person',
                ])
                ->get(['id', 'plate', 'vehicle_type_id', 'is_third_party', 'third_party_id']),
            'drivers' => Driver::query()
                ->where('license_due_at', '>', Carbon::now((string) config('app.operation_tz'))->utc())
                ->get(['id', 'first_name', 'first_lastname', 'identification_number', 'license_due_at', 'timezone', 'eps_id', 'pension_fund_id']),
            'contracts' => Contract::query()
                ->where('active', true)
                ->with('thirdParty:id,identification_number,first_name,first_lastname,company_name,is_natural_person')
                ->get(['id', 'contract_number', 'third_party_id', 'contract_object', 'start_at', 'end_at', 'timezone', 'is_generic', 'billing_unit_type']),
            'municipalities' => Municipality::query()
                ->with('department:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id', 'latitude', 'longitude']),
        ];
    }

    /**
     * Q5 / bug-log:BUG-01 — when the picked contract does not cover the
     * service date and the request carries `create_generic_contract=true`,
     * create a GEN-NNNN-YYYY generic contract for the original contract's
     * tercero (sized to cover the service date) and return its id so the
     * caller can substitute it. Returns null when no bridging is required.
     */
    private function maybeBridgeToGenericContract(\App\Http\Requests\ServiceStoreRequest $request, array $data): ?int
    {
        if (! $request->boolean('create_generic_contract')) {
            return null;
        }

        $contract = Contract::find($data['contract_id'] ?? null);
        if (! $contract) {
            return null;
        }

        $instant = isset($data['planned_start_at'])
            ? \Carbon\CarbonImmutable::parse($data['planned_start_at'])->utc()
            : null;
        if ($instant === null) {
            return null;
        }

        // Already covers — no bridge needed.
        if ($contract->start_at && $contract->end_at
            && $instant->gte($contract->start_at)
            && $instant->lt($contract->end_at)) {
            return null;
        }

        $year = $instant->copy()->setTimezone((string) ($data['timezone'] ?? 'America/Bogota'))->year;
        $sequence = Contract::query()
            ->where('contract_number', 'like', "GEN-%-{$year}")
            ->count() + 1;

        $tz = (string) ($data['timezone'] ?? 'America/Bogota');
        $startAt = \Carbon\CarbonImmutable::parse($data['service_date_local'].' 00:00:00', $tz)->utc();
        $endAt = $startAt->addDay();

        $generic = Contract::create([
            'contract_number' => sprintf('GEN-%04d-%d', $sequence, $year),
            'is_generic' => true,
            'active' => true,
            'third_party_id' => $contract->third_party_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'timezone' => $tz,
            'contract_object' => $contract->contract_object,
            'billing_unit_type' => $contract->billing_unit_type,
            'route_description' => 'Contrato genérico auto-creado para servicio fuera de ventana',
        ]);

        activity()
            ->performedOn($generic)
            ->causedBy($request->user())
            ->withProperties([
                'auto_generated_from_service' => true,
                'original_contract_id' => $contract->id,
            ])
            ->log('Contrato genérico auto-creado');

        return $generic->id;
    }
}
