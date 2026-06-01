<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\IncidentTypeStoreRequest;
use App\Http\Requests\IncidentTypeUpdateRequest;
use App\Models\IncidentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class IncidentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::VIEW_INCIDENT_TYPES->value);

        $incidentTypes = QueryBuilder::for(IncidentType::class)
            ->allowedFilters([
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'code',
                'name',
                'severity',
            ])
            ->allowedSorts(['code', 'name', 'severity'])
            ->get();

        return Inertia::render('incident-types/index', [
            'incidentTypes' => $incidentTypes,
        ]);
    }

    public function store(IncidentTypeStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_INCIDENT_TYPES->value);

        IncidentType::create($request->validated());

        return back()->with('success', 'Tipo de novedad creado.');
    }

    public function show(Request $request, IncidentType $incidentType): Response
    {
        Gate::authorize(Permission::VIEW_INCIDENT_TYPES->value);

        return Inertia::render('incident-types/show', [
            'incidentType' => $incidentType,
        ]);
    }

    public function update(IncidentTypeUpdateRequest $request, IncidentType $incidentType): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_INCIDENT_TYPES->value);

        $incidentType->update($request->validated());

        return back()->with('success', 'Tipo de novedad actualizado.');
    }

    public function destroy(Request $request, IncidentType $incidentType): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_INCIDENT_TYPES->value);

        $incidentType->delete();

        return redirect()->route('incident-types.index');
    }
}
