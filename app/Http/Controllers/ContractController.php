<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\ContractStoreRequest;
use App\Http\Requests\ContractUpdateRequest;
use App\Models\Contract;
use App\Models\DocumentType;
use App\Models\Municipality;
use App\Models\Service;
use App\Models\ServiceIncident;
use App\Models\ThirdParty;
use App\Support\FacetCounts;
use App\Support\Tz;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ContractController extends Controller
{
    /**
     * Days-ahead window used by the `por_vencer` bucket of the
     * contract_status filter. Mirrors `CONTRACT_EXPIRY_ALERT_DAYS`
     * in DashboardController and `CONTRACT_EXPIRY_WINDOW_DAYS` in
     * `resources/js/lib/document-status.ts`.
     */
    private const CONTRACT_EXPIRY_WINDOW_DAYS = 60;

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_CONTRACTS->value);

        $contracts = QueryBuilder::for(Contract::class)
            ->with([
                'thirdParty:id,document_type_id,identification_number,is_natural_person,first_name,first_lastname,company_name,is_customer,is_provider',
                'thirdParty.documentType:id,code,name',
            ])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts(['contract_number', 'start_at', 'end_at', 'created_at'])
            ->defaultSort('-created_at')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($contracts);
        }

        return Inertia::render('contracts/index', [
            'contracts' => $contracts,
            'facetCounts' => FacetCounts::for(
                Contract::class,
                $this->allowedFilters(),
                $request,
                ['third_party_id' => 'third_party_id'],
            ),
            ...$this->modalReferenceData(),
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
            'contract_number',
            AllowedFilter::exact('contract_object'),
            AllowedFilter::exact('is_generic'),
            AllowedFilter::exact('active'),
            AllowedFilter::exact('third_party_id'),
            AllowedFilter::callback('contract_status', function (Builder $query, $value) {
                $first = is_array($value) ? ($value[0] ?? '') : explode(',', (string) $value)[0];
                $this->applyContractStatusFilter($query, $first);
            }),
        ];
    }

    /**
     * Reference data shared by the contract create/edit modal — customer
     * third parties for the Cliente combobox, plus document types and
     * municipalities for the nested "crear cliente" dialog.
     *
     * @return array{thirdParties: \Illuminate\Database\Eloquent\Collection<int, ThirdParty>, documentTypes: \Illuminate\Database\Eloquent\Collection<int, DocumentType>, municipalities: \Illuminate\Database\Eloquent\Collection<int, Municipality>}
     */
    private function modalReferenceData(): array
    {
        return [
            'thirdParties' => $this->customerOptions(),
            'documentTypes' => DocumentType::orderBy('code')->get(['id', 'code', 'name']),
            'municipalities' => Municipality::query()
                ->with('department:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'department_id']),
        ];
    }

    /**
     * Filter contracts by the four-state temporal model
     * (vigente / por_vencer / vencido / inactivo). The dashboard
     * deep-links use the aliased `expiring_soon` / `expired` vocabulary;
     * both forms MUST resolve to the same bucket.
     */
    private function applyContractStatusFilter(Builder $query, string $value): void
    {
        $now = CarbonImmutable::now(Tz::operation());
        $thresholdInstant = $now->addDays(self::CONTRACT_EXPIRY_WINDOW_DAYS)->utc();
        $nowInstant = $now->utc();

        $normalized = match ($value) {
            'expiring_soon' => 'por_vencer',
            'expired' => 'vencido',
            default => $value,
        };

        match ($normalized) {
            'inactivo' => $query->where('active', false),
            'vencido' => $query
                ->where('active', true)
                ->where(function (Builder $q) use ($nowInstant): void {
                    $q->whereNull('end_at')->orWhere('end_at', '<=', $nowInstant);
                }),
            'por_vencer' => $query
                ->where('active', true)
                ->whereNotNull('end_at')
                ->where('end_at', '>', $nowInstant)
                ->where('end_at', '<=', $thresholdInstant),
            'vigente' => $query
                ->where('active', true)
                ->whereNotNull('end_at')
                ->where('end_at', '>', $thresholdInstant),
            default => null,
        };
    }

    /**
     * Build the customer option list used by the create/edit modal and
     * the above-the-table combobox filter. Returns only `is_customer =
     * true` third parties with the minimum fields the
     * `<ThirdPartyCombobox />` needs.
     */
    private function customerOptions(): \Illuminate\Database\Eloquent\Collection
    {
        return ThirdParty::query()
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
            ]);
    }

    public function store(ContractStoreRequest $request): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_CONTRACTS->value);

        $data = $request->validated();

        if ($request->boolean('is_generic') && empty($data['contract_number'])) {
            $year = now()->year;
            $sequence = Contract::query()
                ->where('contract_number', 'like', "GEN-%-{$year}")
                ->count() + 1;
            $data['contract_number'] = sprintf('GEN-%04d-%d', $sequence, $year);
        }

        $contract = Contract::create($data);

        // Cascade-create flow: parent modal (e.g. service form opens this
        // dialog) needs to auto-select the new contract. Stay on the
        // current page; surface the id via flash data.
        if ($request->boolean('_cascade')) {
            return back()->with('created_contract_id', $contract->id);
        }

        return back()->with('success', 'Contrato creado.');
    }

    public function show(Request $request, Contract $contract): Response
    {
        Gate::authorize(Permission::VIEW_CONTRACTS->value);

        $contract->load([
            'thirdParty:id,document_type_id,identification_number,is_natural_person,first_name,first_lastname,company_name,is_customer,is_provider',
            'thirdParty.documentType:id,code,name',
        ]);

        $recentServices = Service::query()
            ->where('contract_id', $contract->id)
            ->with([
                'vehicle:id,plate',
                'driver:id,first_name,first_lastname',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('planned_start_at')
            ->limit(5)
            ->get(['id', 'service_date_local', 'planned_start_at', 'timezone', 'service_status', 'vehicle_id', 'driver_id', 'contract_id']);

        // Aggregate billing-affecting incidents across every service of
        // the contract — gives a "is this contract bleeding money via
        // recurring novedades?" indicator without listing each one.
        $incidentsRow = ServiceIncident::query()
            ->whereHas('service', fn ($q) => $q->where('contract_id', $contract->id))
            ->where('affects_billing', true)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(additional_value), 0) AS amount')
            ->first();

        $incidentsBillingImpact = [
            'count' => (int) ($incidentsRow->count ?? 0),
            'amount' => (float) ($incidentsRow->amount ?? 0),
        ];

        return Inertia::render('contracts/show', [
            'contract' => $contract,
            'recentServices' => $recentServices,
            'incidentsBillingImpact' => $incidentsBillingImpact,
            ...$this->modalReferenceData(),
        ]);
    }

    public function update(ContractUpdateRequest $request, Contract $contract): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_CONTRACTS->value);
        $contract->update($request->validated());

        return back()->with('success', 'Contrato actualizado.');
    }

    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_CONTRACTS->value);
        $contract->delete();

        return redirect()->route('contracts.index');
    }
}
