<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\VehicleLocationStoreRequest;
use App\Http\Requests\VehicleLocationUpdateRequest;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VehicleLocationController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_VEHICLE_LOCATIONS->value);

        $locations = QueryBuilder::for(VehicleLocation::class)
            ->with([
                'vehicle:id,plate',
                'service:id,service_date_local,planned_start_at,timezone',
                'capturedBy:id,name',
            ])
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                AllowedFilter::exact('vehicle_id'),
                AllowedFilter::exact('is_manual'),
                AllowedFilter::callback('recorded_from', function (Builder $query, $value): void {
                    $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                    if ($value === '') {
                        return;
                    }
                    $query->whereDate('recorded_at', '>=', $value);
                }),
                AllowedFilter::callback('recorded_to', function (Builder $query, $value): void {
                    $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                    if ($value === '') {
                        return;
                    }
                    $query->whereDate('recorded_at', '<=', $value);
                }),
            ])
            ->allowedSorts(...['recorded_at', 'vehicle_id', 'created_at'])
            ->defaultSort('-recorded_at', '-id')
            ->paginate($request->perPage())
            ->withQueryString();

        // useServerTable in-app fetches (filter / sort / pagination) set
        // `Accept: application/json` without the `X-Inertia` header, so
        // Laravel's Inertia middleware would otherwise return the full
        // HTML page and `response.json()` in the hook would die with
        // `SyntaxError: JSON.parse: unexpected character at line 1
        // column 1 of the JSON data` (audit F-stale-json-parse).
        if ($request->wantsJson()) {
            return response()->json($locations);
        }

        return Inertia::render('vehicle-locations/index', [
            'vehicleLocations' => $locations,
            'vehicles' => Vehicle::query()
                ->orderBy('plate')
                ->get(['id', 'plate', 'brand', 'line']),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize(Permission::REGISTER_VEHICLE_LOCATION->value);

        return Inertia::render('vehicle-locations/create', [
            'vehicles' => Vehicle::query()
                ->orderBy('plate')
                ->get(['id', 'plate', 'brand', 'line']),
        ]);
    }

    public function store(VehicleLocationStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::REGISTER_VEHICLE_LOCATION->value);

        $data = $request->validated();
        $data['captured_by'] ??= $request->user()?->id;

        VehicleLocation::create($data);

        return redirect()->route('vehicle-locations.index');
    }

    public function show(Request $request, VehicleLocation $vehicleLocation): Response
    {
        Gate::authorize(Permission::VIEW_VEHICLE_LOCATIONS->value);

        $vehicleLocation->load([
            'vehicle:id,plate,brand,line',
            'service:id,service_date_local,planned_start_at,timezone,vehicle_id',
            'capturedBy:id,name,email',
        ]);

        return Inertia::render('vehicle-locations/show', [
            'vehicleLocation' => $vehicleLocation,
        ]);
    }

    public function edit(Request $request, VehicleLocation $vehicleLocation): Response
    {
        Gate::authorize(Permission::REGISTER_VEHICLE_LOCATION->value);

        return Inertia::render('vehicle-locations/edit', [
            'vehicleLocation' => $vehicleLocation,
            'vehicles' => Vehicle::query()
                ->orderBy('plate')
                ->get(['id', 'plate', 'brand', 'line']),
        ]);
    }

    public function update(VehicleLocationUpdateRequest $request, VehicleLocation $vehicleLocation): RedirectResponse
    {
        Gate::authorize(Permission::REGISTER_VEHICLE_LOCATION->value);

        $vehicleLocation->update($request->validated());

        return redirect()->route('vehicle-locations.index');
    }

    public function destroy(Request $request, VehicleLocation $vehicleLocation): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_VEHICLE_LOCATIONS->value);

        $vehicleLocation->delete();

        return redirect()->route('vehicle-locations.index');
    }
}
