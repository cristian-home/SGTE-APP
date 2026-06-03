<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\ServiceStatus;
use App\Http\Requests\InvoiceServiceAttachRequest;
use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ThirdParty;
use App\Services\InvoiceTotalCalculator;
use App\Support\FacetCounts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class InvoiceController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_INVOICES->value);

        $invoices = QueryBuilder::for(Invoice::class)
            ->with([
                'thirdParty:id,document_type_id,identification_number,is_natural_person,first_name,first_lastname,company_name,is_customer,is_provider',
                'thirdParty.documentType:id,code,name',
            ])
            // services_count drives the locked-total state of the edit modal.
            ->withCount('services')
            ->allowedFilters(...$this->allowedFilters())
            ->allowedSorts(...['invoice_number', 'issued_at', 'total_value', 'payment_status'])
            ->defaultSort('-issued_at')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($invoices);
        }

        return Inertia::render('invoices/index', [
            'invoices' => $invoices,
            'thirdParties' => $this->customerOptions(),
            'nextInvoiceNumberPreview' => Invoice::nextInvoiceNumber(),
            'facetCounts' => FacetCounts::for(
                Invoice::class,
                $this->allowedFilters(),
                $request,
                ['third_party_id' => 'third_party_id'],
            ),
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
            'invoice_number',
            AllowedFilter::exact('payment_status'),
            AllowedFilter::exact('third_party_id'),
        ];
    }

    /**
     * JSON endpoint consumed by the create-invoice dialog. Returns the
     * services eligible for billing under the given customer, partitioned
     * into clean/blocked the same way the show page hydrates the picker.
     *
     * Lives on its own route (instead of as an `?eligible_for=` branch
     * on index) so the create dialog can fetch it with a plain HTTP
     * request — no Inertia visit, no URL mutation, no view transition.
     */
    public function eligibleServices(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ASSIGN_SERVICES_TO_INVOICES->value);

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:third_parties,id'],
        ]);

        $candidates = $this->candidatesForCustomer((int) $validated['customer_id']);
        [$clean, $blocked] = $candidates->partition(
            fn (Service $service) => ! $service->serviceIncidents
                ->where('affects_billing', true)
                ->isNotEmpty(),
        );

        return response()->json([
            'cleanCandidates' => $clean->values(),
            'blockedCandidates' => $blocked->values(),
        ]);
    }

    /**
     * JSON list of services already attached to the given invoice,
     * shaped like ServicePickerRow so the edit dialog can render them
     * in the same picker component as the eligible (clean / blocked)
     * candidates.
     */
    public function attachedServices(Request $request, Invoice $invoice): JsonResponse
    {
        Gate::authorize(Permission::VIEW_INVOICES->value);

        $services = Service::query()
            ->where('invoice_id', $invoice->id)
            ->with([
                'vehicle:id,plate',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number',
                'serviceIncidents:id,service_id,affects_billing,additional_value',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('id')
            ->get([
                'id',
                'service_number',
                'service_date_local',
                'planned_start_at',
                'timezone',
                'vehicle_id',
                'driver_id',
                'contract_id',
                'unit_value',
                'quantity',
                'billing_groups',
                'service_status',
            ]);

        return response()->json(['attachedCandidates' => $services]);
    }

    /**
     * Shared customer option list — used by the create/edit modal and
     * the above-the-table combobox filter. Mirrors
     * ContractController::customerOptions.
     *
     * @return Collection<int, ThirdParty>
     */
    private function customerOptions(): Collection
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

    public function store(InvoiceStoreRequest $request, InvoiceTotalCalculator $calculator): RedirectResponse
    {
        Gate::authorize(Permission::CREATE_INVOICES->value);

        $serviceIds = $request->validated('service_ids') ?? [];
        $justification = trim((string) $request->input('override_justification'));

        // Guard: attaching services requires the dedicated permission.
        // We fail loudly instead of silently dropping service_ids so the
        // operator never thinks the assignment succeeded.
        if (
            count($serviceIds) > 0 &&
            ! Gate::allows(Permission::ASSIGN_SERVICES_TO_INVOICES->value)
        ) {
            throw ValidationException::withMessages([
                'service_ids' => 'No tienes permiso para asociar servicios a una factura.',
            ]);
        }

        // Auto-numeración con red de seguridad a tres capas: cómputo
        // monotónico en el server, UNIQUE constraint en la BD, y retry
        // ante choque de concurrencia. El invoice_number que llegue del
        // cliente se ignora deliberadamente.
        $invoice = null;
        $attempts = 0;
        while ($attempts < 3) {
            try {
                $invoice = DB::transaction(function () use ($request, $serviceIds, $justification, $calculator) {
                    $data = $request->validated();
                    $data['invoice_number'] = Invoice::nextInvoiceNumber();

                    $created = Invoice::create($data);

                    if (count($serviceIds) > 0) {
                        $this->validateAttachableServices($created, $serviceIds, $justification);
                        $this->attachServiceIdsToInvoice(
                            $created,
                            $serviceIds,
                            $justification,
                            $request->user()?->id,
                        );
                        $calculator->recomputeFor($created->fresh());
                    }

                    return $created;
                });
                break;
            } catch (UniqueConstraintViolationException $e) {
                $attempts++;
                if ($attempts >= 3) {
                    throw $e;
                }
            }
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Factura creada'.(count($serviceIds) > 0 ? ' con '.count($serviceIds).' servicio(s) asociado(s).' : '.'));
    }

    public function show(Request $request, Invoice $invoice, InvoiceTotalCalculator $calculator): Response
    {
        Gate::authorize(Permission::VIEW_INVOICES->value);

        $invoice->load([
            'thirdParty:id,document_type_id,identification_number,is_natural_person,first_name,first_lastname,company_name,is_customer,is_provider',
            'thirdParty.documentType:id,code,name',
        ]);
        $invoice->loadCount('services');

        $recentServices = Service::query()
            ->where('invoice_id', $invoice->id)
            ->with([
                'vehicle:id,plate',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number',
                // Mirrors what the PDF builder loads (see ::pdf()) so the
                // web breakdown table is fed from the same shape and the
                // numbers cannot drift from what the client downloads.
                'serviceIncidents' => fn ($q) => $q->where('affects_billing', true),
                'serviceIncidents.incidentType:id,name',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('planned_start_at')
            ->limit(5)
            ->get([
                'id',
                'service_number',
                'service_date_local',
                'service_status',
                'vehicle_id',
                'driver_id',
                'contract_id',
                'invoice_id',
                'unit_value',
                'quantity',
                'planned_start_at',
                'timezone',
            ]);

        $candidates = $this->candidateServices($invoice);
        [$cleanCandidates, $blockedCandidates] = $candidates->partition(
            fn (Service $service) => ! $service->serviceIncidents
                ->where('affects_billing', true)
                ->isNotEmpty(),
        );

        // Same accumulators the PDF builder uses (see ::pdf() block
        // around line 320-335). Keeping them server-side guarantees the
        // web breakdown footer and the downloaded PDF agree to the cent
        // for the same invoice.
        $invoice->loadMissing('services.serviceIncidents');
        $subtotalServices = (float) $invoice->services->sum(
            fn ($service) => (float) $service->unit_value * (int) $service->quantity,
        );
        $subtotalIncidents = (float) $invoice->services->sum(
            fn ($service) => $service->serviceIncidents
                ->where('affects_billing', true)
                ->sum(fn ($incident) => (float) ($incident->additional_value ?? 0)),
        );

        return Inertia::render('invoices/show', [
            'invoice' => $invoice,
            'recentServices' => $recentServices,
            'computedTotal' => $calculator->computeFor($invoice),
            'billingTotals' => [
                'subtotal_services' => $subtotalServices,
                'subtotal_incidents' => $subtotalIncidents,
                'grand_total' => $subtotalServices + $subtotalIncidents,
            ],
            'candidateServices' => $cleanCandidates->values(),
            'blockedCandidateServices' => $blockedCandidates->values(),
            'thirdParties' => $this->customerOptions(),
        ]);
    }

    public function update(
        InvoiceUpdateRequest $request,
        Invoice $invoice,
        InvoiceTotalCalculator $calculator,
    ): RedirectResponse {
        Gate::authorize(Permission::UPDATE_INVOICES->value);

        $data = $request->validated();
        $serviceIdsProvided = $request->has('service_ids');
        $desiredIds = $serviceIdsProvided
            ? array_map('intval', $request->input('service_ids') ?? [])
            : null;
        $justification = trim((string) $request->input('override_justification'));

        // El cliente no se puede CAMBIAR mientras la factura tenga
        // servicios asociados — los servicios pertenecen al tercero, y
        // moverla de cliente dejaría las relaciones inconsistentes.
        // Excepción: si current es null (factura legacy sin cliente),
        // ASIGNAR uno por primera vez está permitido — no rompe
        // relaciones porque cualquier service_id ya está validado por
        // validateAttachableServices contra el nuevo customer.
        if (
            isset($data['third_party_id']) &&
            $invoice->third_party_id !== null &&
            (int) $data['third_party_id'] !== (int) $invoice->third_party_id &&
            $invoice->services()->exists()
        ) {
            throw ValidationException::withMessages([
                'third_party_id' => 'No puedes cambiar el cliente mientras la factura tenga servicios asociados.',
            ]);
        }

        // Pre-condición del flujo con servicios: si llegan service_ids
        // (incluso un set vacío que detacha todo), el usuario necesita
        // el permiso de asignación.
        if (
            $serviceIdsProvided &&
            ! Gate::allows(Permission::ASSIGN_SERVICES_TO_INVOICES->value)
        ) {
            throw ValidationException::withMessages([
                'service_ids' => 'No tienes permiso para asociar o desvincular servicios.',
            ]);
        }

        // Quitar service_ids/override_justification del array de datos
        // que se pasa a `$invoice->update()` — esos campos no son
        // mass-assignable y se manejan aparte.
        unset($data['service_ids'], $data['override_justification']);

        DB::transaction(function () use ($invoice, $data, $desiredIds, $justification, $calculator, $request) {
            $invoice->update($data);

            if ($desiredIds !== null) {
                $currentIds = $invoice->services()->pluck('id')->map(fn ($id) => (int) $id)->all();
                $toAttach = array_values(array_diff($desiredIds, $currentIds));
                $toDetach = array_values(array_diff($currentIds, $desiredIds));

                if (! empty($toAttach)) {
                    $this->validateAttachableServices($invoice, $toAttach, $justification);
                    $this->attachServiceIdsToInvoice(
                        $invoice,
                        $toAttach,
                        $justification,
                        $request->user()?->id,
                    );
                }

                if (! empty($toDetach)) {
                    Service::query()
                        ->whereIn('id', $toDetach)
                        ->where('invoice_id', $invoice->id)
                        ->update(['invoice_id' => null]);
                }

                $calculator->recomputeFor($invoice->fresh());
            }
        });

        return back()->with('success', 'Factura actualizada.');
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize(Permission::DELETE_INVOICES->value);
        $invoice->delete();

        return redirect()->route('invoices.index');
    }

    /**
     * Transition a pending invoice to paid. Rejected with 422 when the
     * invoice is already paid or overdue — the intent of this route is
     * to mark a billable invoice as collected, not to overwrite a
     * terminal state. Future transitions (mark-overdue, cancel, refund)
     * get their own dedicated methods.
     */
    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        Gate::authorize(Permission::UPDATE_INVOICES->value);

        if ($invoice->payment_status !== PaymentStatus::Pending) {
            throw ValidationException::withMessages([
                'payment_status' => 'Solo facturas pendientes pueden marcarse como pagadas.',
            ]);
        }

        $invoice->update(['payment_status' => PaymentStatus::Paid]);

        return redirect()->route('invoices.show', $invoice);
    }

    /**
     * Attach one or more closed services to the invoice and recompute
     * the total. The InvoiceServiceAttachRequest after() hook validates
     * that all target services belong to the invoice's customer, are
     * closed, and are not already attached to a different invoice.
     */
    public function attachServices(
        InvoiceServiceAttachRequest $request,
        Invoice $invoice,
        InvoiceTotalCalculator $calculator,
    ): RedirectResponse {
        Gate::authorize(Permission::ASSIGN_SERVICES_TO_INVOICES->value);

        $ids = $request->validated('service_ids');
        $justification = trim((string) $request->input('override_justification'));

        DB::transaction(function () use ($invoice, $ids, $justification, $request) {
            $this->attachServiceIdsToInvoice(
                $invoice,
                $ids,
                $justification,
                $request->user()?->id,
            );
        });

        $calculator->recomputeFor($invoice->fresh());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', count($ids).' servicio(s) asociado(s).');
    }

    /**
     * Apply the same domain rules that InvoiceServiceAttachRequest::after()
     * enforces. Used by store() (which bypasses the dedicated request
     * since service_ids is optional there) so the inline create-flow
     * cannot leak invalid attachments past validation.
     *
     * @param  array<int>  $serviceIds
     *
     * @throws ValidationException
     */
    private function validateAttachableServices(Invoice $invoice, array $serviceIds, string $justification): void
    {
        // Sin cliente no hay regla 'mismo cliente' que validar — y
        // attachear servicios a una factura sin tercero deja
        // inconsistente el reporte. Falla con mensaje explícito en vez
        // del genérico 'no pertenecen al cliente'.
        if ($invoice->third_party_id === null) {
            throw ValidationException::withMessages([
                'service_ids' => 'La factura no tiene cliente asignado. Asigna uno antes de asociar servicios.',
            ]);
        }

        $services = Service::query()
            ->with([
                'contract:id,third_party_id',
                'serviceIncidents' => fn ($q) => $q
                    ->where('affects_billing', true)
                    ->with('incidentType:id,name'),
            ])
            ->whereIn('id', $serviceIds)
            ->get();

        $errors = [];

        foreach ($services as $service) {
            if ($service->contract?->third_party_id !== $invoice->third_party_id) {
                $errors['service_ids'] = 'Los servicios deben pertenecer al cliente de la factura.';
                break;
            }
        }

        foreach ($services as $service) {
            if (! isset($errors['service_ids']) && $service->invoice_id !== null && $service->invoice_id !== $invoice->id) {
                $errors['service_ids'] = 'Uno o más servicios ya están asociados a otra factura.';
                break;
            }
        }

        foreach ($services as $service) {
            if (! isset($errors['service_ids'])) {
                $status = $service->service_status;
                $value = $status instanceof ServiceStatus ? $status->value : $status;
                if ($value !== ServiceStatus::Closed->value) {
                    $errors['service_ids'] = 'Solo servicios cerrados pueden facturarse.';
                    break;
                }
            }
        }

        if (! isset($errors['service_ids'])) {
            $blocked = $services->filter(
                fn (Service $service) => $service->serviceIncidents->isNotEmpty(),
            );
            if ($blocked->isNotEmpty() && $justification === '') {
                $names = $blocked
                    ->map(function (Service $service): string {
                        $firstIncidentType = $service->serviceIncidents->first()?->incidentType?->name ?? 'novedad';

                        return "#{$service->id} ({$firstIncidentType})";
                    })
                    ->implode(', ');
                $errors['service_ids'] = "Los siguientes servicios tienen novedades que afectan la facturación y requieren justificación: {$names}.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Update service.invoice_id for the given ids and log the override
     * activity when blocked services are being force-attached with a
     * justification. Centralized so store() and attachServices() share
     * the same audit trail format.
     *
     * @param  array<int>  $serviceIds
     */
    private function attachServiceIdsToInvoice(
        Invoice $invoice,
        array $serviceIds,
        string $justification,
        ?int $userId,
    ): void {
        $overriddenServiceIds = [];
        if ($justification !== '') {
            $overriddenServiceIds = Service::query()
                ->whereIn('id', $serviceIds)
                ->whereHas(
                    'serviceIncidents',
                    fn ($q) => $q->where('affects_billing', true),
                )
                ->pluck('id')
                ->all();
        }

        Service::query()
            ->whereIn('id', $serviceIds)
            ->update(['invoice_id' => $invoice->id]);

        if ($justification !== '' && $overriddenServiceIds !== []) {
            $log = activity()->performedOn($invoice);
            if ($userId !== null) {
                $log = $log->causedBy($userId);
            }
            $log
                ->withProperties([
                    'override_justification' => $justification,
                    'overridden_service_ids' => $overriddenServiceIds,
                    'attached_service_ids' => $serviceIds,
                ])
                ->log('Servicios con novedades facturables asociados con justificación');
        }
    }

    /**
     * Detach a service from the invoice by nulling its invoice_id and
     * recomputing the total. Returns 404 if the service is not attached
     * to this invoice (defensive; the route already binds both params).
     */
    public function detachService(
        Request $request,
        Invoice $invoice,
        Service $service,
        InvoiceTotalCalculator $calculator,
    ): RedirectResponse {
        Gate::authorize(Permission::ASSIGN_SERVICES_TO_INVOICES->value);

        if ($service->invoice_id !== $invoice->id) {
            abort(404);
        }

        $service->update(['invoice_id' => null]);
        $calculator->recomputeFor($invoice->fresh());

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Servicio desvinculado.');
    }

    /**
     * Idempotent recompute. Covers the drift case where a linked
     * service's unit_value/quantity or a linked incident's
     * affects_billing/additional_value changes after attach-time.
     */
    public function recomputeTotal(
        Request $request,
        Invoice $invoice,
        InvoiceTotalCalculator $calculator,
    ): RedirectResponse {
        Gate::authorize(Permission::ASSIGN_SERVICES_TO_INVOICES->value);

        $calculator->recomputeFor($invoice);

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Total recalculado.');
    }

    /**
     * Services the <ServicePickerDialog /> on the invoice show page
     * can offer for attachment: closed, unbilled, within the last
     * 90 days, and belonging to the invoice's customer.
     *
     * Shipped alongside `invoice` on the show payload so the picker's
     * first open is hydrated (see Notes in the requirement doc).
     *
     * @return Collection<int, Service>
     */
    /**
     * Stream an informational PDF of the invoice inline.
     *
     * Explicitly NOT a fiscal document (DIAN compliance requires a
     * certified e-invoice provider integration — separate project).
     * The PDF carries a prominent INFORMATIVO badge and a footer
     * disclaimer on every page.
     *
     * Side-effect: recomputes `invoice.total_value` via the calculator
     * before rendering so the printed total matches the current
     * attached services + billing-affecting incidents. Justified
     * because a stale total leaking into a customer-facing PDF is a
     * bigger problem than the minor activity-log noise when nothing
     * changed.
     */
    public function pdf(Request $request, Invoice $invoice, InvoiceTotalCalculator $calculator): HttpResponse
    {
        Gate::authorize(Permission::VIEW_INVOICES->value);

        $calculator->recomputeFor($invoice->fresh());

        $invoice = $invoice->fresh()->load([
            'thirdParty.documentType',
            'thirdParty.municipality.department',
            'services' => fn ($q) => $q->orderBy('service_date_local'),
            'services.vehicle:id,plate',
            'services.contract:id,contract_number',
            'services.serviceIncidents' => fn ($q) => $q->where('affects_billing', true),
            'services.serviceIncidents.incidentType:id,name',
        ]);

        $services = $invoice->services;
        $billingIncidents = $services->flatMap(fn ($s) => $s->serviceIncidents)->values();

        $subtotalServices = (float) $services->sum(fn ($s) => (float) $s->unit_value * (int) $s->quantity);
        $subtotalIncidents = (float) $billingIncidents->sum(fn ($i) => (float) ($i->additional_value ?? 0));
        $grandTotal = $subtotalServices + $subtotalIncidents;

        $thirdParty = $invoice->thirdParty;
        $customerName = $this->customerNameFor($thirdParty);
        $customerDocument = trim(
            ($thirdParty?->documentType?->code ?? '').' '.($thirdParty?->identification_number ?? ''),
        );

        $customerAddressLine = collect([
            $thirdParty?->municipality?->name,
            $thirdParty?->municipality?->department?->name,
            $thirdParty?->address,
        ])->filter()->implode(' — ');

        $nowFormatted = Carbon::now()->locale('es_CO')->isoFormat('LLLL');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'services' => $services,
            'billing_incidents' => $billingIncidents,
            'subtotal_services' => $subtotalServices,
            'subtotal_incidents' => $subtotalIncidents,
            'grand_total' => $grandTotal,
            'customer_name' => $customerName,
            'customer_document' => $customerDocument,
            'customer_address_line' => $customerAddressLine,
            'now_formatted' => $nowFormatted,
        ])->setPaper('letter');

        return $pdf->stream(
            "factura-{$invoice->invoice_number}.pdf",
            ['Attachment' => false],
        );
    }

    /**
     * Resolve the customer display name — legal persons print their
     * company name, natural persons get their first + last name.
     */
    private function customerNameFor(?ThirdParty $tp): string
    {
        if ($tp === null) {
            return '—';
        }

        if ($tp->is_natural_person) {
            $name = trim(($tp->first_name ?? '').' '.($tp->first_lastname ?? ''));

            return $name !== '' ? $name : '—';
        }

        return $tp->company_name ?? '—';
    }

    private function candidateServices(Invoice $invoice): Collection
    {
        // Defensive: invoices may have a null third_party_id (the FK is
        // nullOnDelete in the migration). Skip the candidate query in
        // that case — without a customer there's no useful filter.
        if ($invoice->third_party_id === null) {
            return new Collection;
        }

        return $this->candidatesForCustomer($invoice->third_party_id);
    }

    /**
     * Closed services in the last 90 days that belong to the given
     * customer and aren't already invoiced. Used by the show page
     * picker (via candidateServices) and by the create dialog inline
     * picker (via index?eligible_for=...).
     */
    private function candidatesForCustomer(int $customerId): Collection
    {
        $cutoff = Carbon::now((string) config('app.operation_tz'))->subDays(90)->toDateString();

        return Service::query()
            ->whereNull('invoice_id')
            ->where('service_status', ServiceStatus::Closed->value)
            ->whereDate('service_date_local', '>=', $cutoff)
            ->whereHas('contract', fn ($q) => $q->where('third_party_id', $customerId))
            ->with([
                'vehicle:id,plate',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number',
                'serviceIncidents:id,service_id,affects_billing,additional_value',
            ])
            ->orderByDesc('service_date_local')
            ->orderByDesc('id')
            ->get([
                'id',
                'service_number',
                'service_date_local',
                'planned_start_at',
                'timezone',
                'vehicle_id',
                'driver_id',
                'contract_id',
                'unit_value',
                'quantity',
                'billing_groups',
                'service_status',
            ]);
    }
}
