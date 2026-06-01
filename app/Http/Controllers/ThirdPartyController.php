<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ThirdPartyStoreRequest;
use App\Http\Requests\ThirdPartyUpdateRequest;
use App\Models\Contract;
use App\Models\DocumentType;
use App\Models\Municipality;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ThirdPartyController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_THIRD_PARTIES->value);

        $thirdParties = QueryBuilder::for(ThirdParty::class)
            ->with([
                'municipality:id,name,department_id',
                'municipality.department:id,name',
                'documentType:id,code,name',
            ])
            ->allowedFilters([
                AllowedFilter::callback('search', fn (Builder $query, $value) => $query->searchWithRelevance($value)),
                'identification_number',
                AllowedFilter::exact('is_natural_person'),
                'first_name',
                'first_lastname',
                'company_name',
                AllowedFilter::exact('municipality_id'),
                AllowedFilter::exact('is_customer'),
                AllowedFilter::exact('is_provider'),
                AllowedFilter::exact('active'),
            ])
            ->allowedSorts(['first_name', 'first_lastname', 'company_name', 'municipality_id', 'active'])
            ->defaultSort('id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($thirdParties);
        }

        return Inertia::render('third-parties/index', [
            'thirdParties' => $thirdParties,
            'municipalities' => $this->municipalitiesPayload(),
            'documentTypes' => DocumentType::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Shared municipality payload — eager-loads department for the
     * combobox grouping and sorts by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Municipality>
     */
    private function municipalitiesPayload(): \Illuminate\Database\Eloquent\Collection
    {
        return Municipality::query()
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'department_id']);
    }

    public function store(ThirdPartyStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_THIRD_PARTIES->value);
        $thirdParty = ThirdParty::create($request->validated());

        // Cascade-create flow (service → contract → tercero modal stack):
        // when the caller is a parent modal that needs to auto-select the
        // newly-created row, stay on the current page and surface the new
        // id via flash data instead of redirecting to /third-parties.
        if ($request->boolean('_cascade')) {
            return back()->with('created_third_party_id', $thirdParty->id);
        }

        return back()->with('success', 'Tercero creado.');
    }

    public function show(Request $request, ThirdParty $thirdParty): Response
    {
        Gate::authorize(Permission::VIEW_THIRD_PARTIES->value);

        $thirdParty->load([
            'municipality:id,name,department_id',
            'municipality.department:id,name',
            'documentType:id,code,name',
        ]);

        // Always send both arrays — frontend gates the conditional
        // cards on is_provider / is_customer flags. Empty arrays are a
        // valid state for either role being false.
        $recentVehicles = Vehicle::query()
            ->where('third_party_id', $thirdParty->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'plate', 'internal_code', 'type', 'status']);

        $recentContracts = Contract::query()
            ->where('third_party_id', $thirdParty->id)
            ->orderByDesc('start_at')
            ->limit(5)
            ->get(['id', 'contract_number', 'contract_object', 'start_at', 'end_at', 'timezone', 'active']);

        return Inertia::render('third-parties/show', [
            'thirdParty' => $thirdParty,
            'recentVehicles' => $recentVehicles,
            'recentContracts' => $recentContracts,
            'documentTypes' => DocumentType::orderBy('code')->get(['id', 'code', 'name']),
            'municipalities' => $this->municipalitiesPayload(),
        ]);
    }

    public function update(ThirdPartyUpdateRequest $request, ThirdParty $thirdParty): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_THIRD_PARTIES->value);
        $thirdParty->update($request->validated());

        return back()->with('success', 'Tercero actualizado.');
    }

    public function destroy(Request $request, ThirdParty $thirdParty): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_THIRD_PARTIES->value);
        $thirdParty->delete();

        return redirect()->route('third-parties.index');
    }
}
