<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\SeveranceFundStoreRequest;
use App\Http\Requests\SeveranceFundUpdateRequest;
use App\Models\SeveranceFund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SeveranceFundController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $severanceFunds = QueryBuilder::for(SeveranceFund::class)
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'code',
                'name',
            ])
            ->allowedSorts(...['code', 'name'])
            ->get();

        return Inertia::render('severance-funds/index', [
            'severanceFunds' => $severanceFunds,
        ]);
    }

    public function store(SeveranceFundStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        SeveranceFund::create($request->validated());

        return back()->with('success', 'Fondo de cesantías creado.');
    }

    public function show(Request $request, SeveranceFund $severanceFund): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        return Inertia::render('severance-funds/show', [
            'severanceFund' => $severanceFund,
        ]);
    }

    public function update(SeveranceFundUpdateRequest $request, SeveranceFund $severanceFund): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $severanceFund->update($request->validated());

        return back()->with('success', 'Fondo de cesantías actualizado.');
    }

    public function destroy(Request $request, SeveranceFund $severanceFund): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $severanceFund->delete();

        return redirect()->route('severance-funds.index');
    }
}
