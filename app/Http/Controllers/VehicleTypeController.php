<?php

namespace App\Http\Controllers;

use App\Enums\LicenseCategory;
use App\Enums\Permission;
use App\Http\Requests\VehicleTypeStoreRequest;
use App\Http\Requests\VehicleTypeUpdateRequest;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VehicleTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::VIEW_VEHICLE_TYPES->value);

        $vehicleTypes = QueryBuilder::for(VehicleType::class)
            ->withCount('vehicles')
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'code',
                'name',
                AllowedFilter::exact('active'),
            ])
            ->allowedSorts(...['code', 'name', 'sort_order', 'active'])
            ->defaultSort('sort_order')
            ->get();

        return Inertia::render('vehicle-types/index', [
            'vehicleTypes' => $vehicleTypes,
            'licenseCategories' => array_map(
                fn (LicenseCategory $c) => ['value' => $c->value, 'label' => $c->label()],
                LicenseCategory::cases(),
            ),
        ]);
    }

    public function store(VehicleTypeStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_VEHICLE_TYPES->value);

        VehicleType::create($request->validated());

        return back()->with('success', 'Tipo de vehículo creado.');
    }

    public function update(VehicleTypeUpdateRequest $request, VehicleType $vehicleType): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_VEHICLE_TYPES->value);

        $vehicleType->update($request->validated());

        return back()->with('success', 'Tipo de vehículo actualizado.');
    }

    public function destroy(Request $request, VehicleType $vehicleType): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_VEHICLE_TYPES->value);

        if ($vehicleType->vehicles()->exists()) {
            return back()->with('error', 'No se puede eliminar un tipo de vehículo en uso. Desactívelo en su lugar.');
        }

        $vehicleType->delete();

        return redirect()->route('vehicle-types.index');
    }
}
