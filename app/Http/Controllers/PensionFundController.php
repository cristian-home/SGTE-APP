<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\PensionFundStoreRequest;
use App\Http\Requests\PensionFundUpdateRequest;
use App\Models\PensionFund;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PensionFundController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $pensionFunds = QueryBuilder::for(PensionFund::class)
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'code',
                'name',
            ])
            ->allowedSorts(...['code', 'name'])
            ->get();

        return Inertia::render('pension-funds/index', [
            'pensionFunds' => $pensionFunds,
        ]);
    }

    public function store(PensionFundStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        PensionFund::create($request->validated());

        return back()->with('success', 'Fondo de pensiones creado.');
    }

    public function show(Request $request, PensionFund $pensionFund): Response
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        return Inertia::render('pension-funds/show', [
            'pensionFund' => $pensionFund,
        ]);
    }

    public function update(PensionFundUpdateRequest $request, PensionFund $pensionFund): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $pensionFund->update($request->validated());

        return back()->with('success', 'Fondo de pensiones actualizado.');
    }

    public function destroy(Request $request, PensionFund $pensionFund): RedirectResponse
    {
        Gate::authorize(Permission::MANAGE_CATALOGS->value);

        $pensionFund->delete();

        return redirect()->route('pension-funds.index');
    }
}
