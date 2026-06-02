<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\VehicleStoreRequest;
use App\Http\Requests\VehicleUpdateRequest;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use App\Models\VehicleType;
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

class VehicleController extends Controller
{
    /**
     * Number of days ahead of the service date that counts as
     * "expiring soon" for the docs_status filter. Mirrors the
     * dashboard's Alertas de Documentos threshold.
     */
    private const DOCS_EXPIRY_WINDOW_DAYS = 30;

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_VEHICLES->value);

        $vehicles = QueryBuilder::for(Vehicle::class)
            ->with([
                'vehicleType:id,code,name',
                'thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
                'municipality:id,name,department_id',
                'municipality.department:id,name',
            ])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts(['internal_code', 'plate', 'model_year', 'municipality_id', 'status'])
            ->defaultSort('plate')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($vehicles);
        }

        return Inertia::render('vehicles/index', [
            'vehicles' => $vehicles,
            'suggestedInternalCode' => Vehicle::nextInternalCode(),
            'facetCounts' => FacetCounts::for(
                Vehicle::class,
                $this->allowedFilters(),
                $request,
                ['municipality_id' => 'municipality_id'],
            ),
            ...$this->modalOptions(),
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
            'internal_code',
            'plate',
            'brand',
            AllowedFilter::exact('vehicle_type_id'),
            AllowedFilter::exact('municipality_id'),
            AllowedFilter::exact('is_third_party'),
            AllowedFilter::exact('status'),
            AllowedFilter::callback('docs_status', function (Builder $query, $value) {
                // The faceted filter UI is multi-select but docs_status is
                // semantically single-select. If the URL carries multiple
                // values (comma-separated), honor the first one.
                $first = is_array($value) ? ($value[0] ?? '') : explode(',', (string) $value)[0];
                $this->applyDocsStatusFilter($query, $first);
            }),
            AllowedFilter::callback('soat_expired', fn (Builder $query, $value) => $this->applyDocumentExpiredFilter($query, 'soat_due_at', $value)),
            AllowedFilter::callback('rtm_expired', fn (Builder $query, $value) => $this->applyDocumentExpiredFilter($query, 'rtm_due_at', $value)),
            AllowedFilter::callback('operation_card_expired', fn (Builder $query, $value) => $this->applyDocumentExpiredFilter($query, 'operation_card_due_at', $value)),
        ];
    }

    /**
     * Reference data shared by the vehicle create/edit modal — provider
     * third parties and municipalities (with department for grouping).
     *
     * @return array{thirdParties: \Illuminate\Database\Eloquent\Collection<int, ThirdParty>, municipalities: \Illuminate\Database\Eloquent\Collection<int, Municipality>}
     */
    private function modalOptions(): array
    {
        return [
            'thirdParties' => ThirdParty::query()
                ->where('active', true)
                ->where('is_provider', true)
                ->get(['id', 'identification_number', 'first_name', 'first_lastname', 'company_name', 'is_natural_person']),
            'municipalities' => Municipality::query()
                ->with('department:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id']),
            'vehicleTypes' => VehicleType::query()
                ->activeOrdered()
                ->get(['id', 'code', 'name']),
        ];
    }

    /**
     * Filter vehicles by the aggregate state of their three legal documents.
     *
     * - expired       → at least one document is in the past today
     * - expiring_soon → at least one document is within the 30-day window
     *                   AND no document is already expired
     * - ok            → all three documents are more than 30 days out
     *
     * Composes with the per-document boolean filters via AND.
     */
    private function applyDocsStatusFilter(Builder $query, string $value): void
    {
        $now = Carbon::now((string) config('app.operation_tz'));
        $nowInstant = $now->copy()->utc();
        $thresholdInstant = $now->copy()->addDays(self::DOCS_EXPIRY_WINDOW_DAYS)->utc();

        match ($value) {
            'expired' => $query->where(function (Builder $q) use ($nowInstant): void {
                $q->where('soat_due_at', '<=', $nowInstant)
                    ->orWhere('rtm_due_at', '<=', $nowInstant)
                    ->orWhere('operation_card_due_at', '<=', $nowInstant)
                    ->orWhereNull('soat_due_at')
                    ->orWhereNull('rtm_due_at')
                    ->orWhereNull('operation_card_due_at');
            }),
            'expiring_soon' => $query
                ->where(function (Builder $q) use ($nowInstant, $thresholdInstant): void {
                    // At least one document is in the [now, now+30d] window.
                    $q->whereBetween('soat_due_at', [$nowInstant, $thresholdInstant])
                        ->orWhereBetween('rtm_due_at', [$nowInstant, $thresholdInstant])
                        ->orWhereBetween('operation_card_due_at', [$nowInstant, $thresholdInstant]);
                })
                ->whereNotNull('soat_due_at')
                ->whereNotNull('rtm_due_at')
                ->whereNotNull('operation_card_due_at')
                // …and no document is already expired.
                ->where('soat_due_at', '>', $nowInstant)
                ->where('rtm_due_at', '>', $nowInstant)
                ->where('operation_card_due_at', '>', $nowInstant),
            'ok' => $query
                ->whereNotNull('soat_due_at')
                ->whereNotNull('rtm_due_at')
                ->whereNotNull('operation_card_due_at')
                ->where('soat_due_at', '>', $thresholdInstant)
                ->where('rtm_due_at', '>', $thresholdInstant)
                ->where('operation_card_due_at', '>', $thresholdInstant),
            default => null, // ignore unknown values
        };
    }

    /**
     * Filter vehicles by a single expired document column.
     *
     * Truthy value → keep only vehicles whose given column is null
     * or in the past today.
     */
    private function applyDocumentExpiredFilter(Builder $query, string $column, mixed $value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $nowInstant = Carbon::now((string) config('app.operation_tz'))->utc();

        $query->where(function (Builder $q) use ($column, $nowInstant): void {
            $q->whereNull($column)->orWhere($column, '<=', $nowInstant);
        });
    }

    public function store(VehicleStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_VEHICLES->value);
        Vehicle::create($request->validated());

        return back()->with('success', 'Vehículo creado.');
    }

    public function show(Request $request, Vehicle $vehicle): Response
    {
        Gate::authorize(Permission::VIEW_VEHICLES->value);

        $vehicle->load([
            'vehicleType:id,code,name',
            'municipality:id,name,department_id',
            'municipality.department:id,name',
            'thirdParty:id,company_name,first_name,first_lastname,is_natural_person,identification_number',
        ]);

        $recentServices = Service::query()
            ->where('vehicle_id', $vehicle->id)
            ->with([
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number,third_party_id',
                'contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('planned_start_at')
            ->limit(5)
            ->get();

        $recentLocations = config('sgte.gps_enabled')
            ? $vehicle->locations()
                ->orderByDesc('recorded_at')
                ->limit(10)
                ->get(['id', 'vehicle_id', 'recorded_at', 'latitude', 'longitude', 'is_manual'])
            : collect();

        return Inertia::render('vehicles/show', [
            'vehicle' => $vehicle,
            'recentServices' => $recentServices,
            'recentLocations' => $recentLocations,
            ...$this->modalOptions(),
        ]);
    }

    public function update(VehicleUpdateRequest $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_VEHICLES->value);
        $vehicle->update($request->validated());

        return back()->with('success', 'Vehículo actualizado.');
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_VEHICLES->value);
        $vehicle->delete();

        return redirect()->route('vehicles.index');
    }
}
