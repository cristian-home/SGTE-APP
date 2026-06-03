<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\EpsStoreRequest;
use App\Http\Requests\EpsUpdateRequest;
use App\Models\Eps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EpsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $eps = QueryBuilder::for(Eps::class)
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'code',
                'name',
            ])
            ->allowedSorts(...['code', 'name'])
            ->get();

        return Inertia::render('eps/index', [
            'eps' => $eps,
        ]);
    }

    public function store(EpsStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        Eps::create($request->validated());

        return back()->with('success', 'EPS creada.');
    }

    public function show(Request $request, Eps $ep): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        return Inertia::render('eps/show', [
            'eps' => $ep,
        ]);
    }

    public function update(EpsUpdateRequest $request, Eps $ep): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $ep->update($request->validated());

        return back()->with('success', 'EPS actualizada.');
    }

    public function destroy(Request $request, Eps $ep): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $ep->delete();

        return redirect()->route('eps.index');
    }
}
