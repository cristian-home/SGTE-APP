<?php

namespace App\Http\Controllers;

use App\Enums\FuecStatus;
use App\Enums\Permission;
use App\Enums\ServiceStatus;
use App\Exceptions\FuecRangeExhaustedException;
use App\Http\Requests\FuecCancelRequest;
use App\Http\Requests\FuecStoreRequest;
use App\Models\Fuec;
use App\Models\Service;
use App\Services\FuecGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class FuecController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_FUEC->value);

        $fuecs = QueryBuilder::for(Fuec::class)
            ->with([
                'service:id,service_number,service_date_local,planned_start_at,timezone,vehicle_id,driver_id,contract_id',
                'service.vehicle:id,plate',
                'service.driver:id,first_name,first_lastname',
                'service.contract:id,contract_number',
                'fuecNumberRange:id,resolution_number,resolution_year',
            ])
            ->allowedFilters([
                AllowedFilter::exact('status'),
                'consecutive_number',
            ])
            ->allowedSorts(['consecutive_number', 'generated_at', 'created_at'])
            ->defaultSort('-generated_at', '-id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($request->wantsJson()) {
            return response()->json($fuecs);
        }

        return Inertia::render('fuecs/index', [
            'fuecs' => $fuecs,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize(Permission::GENERATE_FUEC->value);

        return Inertia::render('fuecs/create');
    }

    /**
     * Return JSON list of closed services with no active FUEC, for
     * the Service picker dialog on /fuecs/create.
     */
    public function candidateServices(Request $request): JsonResponse
    {
        Gate::authorize(Permission::GENERATE_FUEC->value);

        $search = (string) $request->query('search', '');

        $candidates = Service::query()
            ->where('service_status', ServiceStatus::Closed)
            ->whereDoesntHave('fuecs', fn ($q) => $q->where('status', FuecStatus::Active))
            ->with([
                'vehicle:id,plate',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number',
            ])
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($inner) use ($search): void {
                    $inner->whereHas('vehicle', fn ($v) => $v->where('plate', 'ilike', "%{$search}%"))
                        ->orWhereHas('contract', fn ($c) => $c->where('contract_number', 'ilike', "%{$search}%"))
                        ->orWhereHas('driver', function ($d) use ($search): void {
                            $d->where('first_name', 'ilike', "%{$search}%")
                                ->orWhere('first_lastname', 'ilike', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('service_date_local')
            ->limit(50)
            ->get(['id', 'service_number', 'service_date_local', 'planned_start_at', 'timezone', 'vehicle_id', 'driver_id', 'contract_id']);

        return response()->json($candidates);
    }

    public function store(FuecStoreRequest $request, FuecGenerator $generator): RedirectResponse
    {
        Gate::authorize(Permission::GENERATE_FUEC->value);

        $service = Service::query()
            ->with(['contract', 'vehicle', 'driver'])
            ->findOrFail($request->input('service_id'));

        try {
            $fuec = $generator->generateFor($service, $request->user());
        } catch (FuecRangeExhaustedException $e) {
            throw ValidationException::withMessages([
                'fuec_pre_generation.range' => $e->getMessage(),
            ]);
        }

        return redirect()->route('fuecs.show', $fuec);
    }

    public function show(Request $request, Fuec $fuec): Response
    {
        Gate::authorize(Permission::VIEW_FUEC->value);

        $fuec->load([
            'service:id,service_number,service_date_local,planned_start_at,planned_end_at,timezone,vehicle_id,driver_id,contract_id,origin_municipality_id,destination_municipality_id,origin_address,destination_address',
            'service.vehicle:id,plate,brand,line,model_year',
            'service.driver:id,first_name,first_lastname,identification_number',
            'service.contract:id,contract_number,third_party_id',
            'service.contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person,identification_number',
            'service.originMunicipality:id,name',
            'service.destinationMunicipality:id,name',
            'fuecNumberRange:id,resolution_number,resolution_year,range_from,range_to',
        ]);

        return Inertia::render('fuecs/show', [
            'fuec' => $fuec,
            'verifyUrl' => route('fuec.verify', ['uuid' => $fuec->uuid]),
        ]);
    }

    public function pdf(Request $request, Fuec $fuec): HttpResponse
    {
        Gate::authorize(Permission::VIEW_FUEC->value);

        if ($fuec->pdf_path === null) {
            abort(404, 'El PDF no está disponible para este FUEC.');
        }

        $disk = Storage::disk($fuec->pdf_disk ?: 's3');

        if (! $disk->exists($fuec->pdf_path)) {
            abort(404, 'El archivo PDF no se encuentra en el almacenamiento.');
        }

        $bytes = $disk->get($fuec->pdf_path);
        $filename = sprintf('fuec-%d.pdf', $fuec->consecutive_number);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename=%s', $filename),
        ]);
    }

    public function cancel(FuecCancelRequest $request, Fuec $fuec): RedirectResponse
    {
        Gate::authorize(Permission::GENERATE_FUEC->value);

        if ($fuec->status !== FuecStatus::Active) {
            throw ValidationException::withMessages([
                'status' => 'Este FUEC ya está anulado.',
            ]);
        }

        $reason = $request->validated('reason');
        $now = now();

        $fuec->update([
            'status' => FuecStatus::Cancelled,
            'cancellation_reason' => $reason,
        ]);

        activity()
            ->performedOn($fuec)
            ->causedBy($request->user())
            ->withProperties([
                'cancellation_reason' => $reason,
                'cancelled_at' => $now->toIso8601String(),
            ])
            ->log('FUEC anulado');

        return redirect()->route('fuecs.show', $fuec);
    }

    /**
     * REQ-007 non-committing preview. Returns the PDF bytes that would
     * be produced for the given service — same validation gauntlet,
     * same Blade template — without consuming a MinTransporte
     * consecutive number or writing a `fuecs` row. Lets operators
     * spot stale contract/customer/driver data before burning a
     * consecutive.
     */
    public function preview(FuecStoreRequest $request, FuecGenerator $generator): HttpResponse
    {
        Gate::authorize(Permission::GENERATE_FUEC->value);

        $service = Service::query()
            ->with(['contract', 'vehicle', 'driver'])
            ->findOrFail($request->input('service_id'));

        try {
            $pdfBytes = $generator->previewFor($service);
        } catch (FuecRangeExhaustedException $e) {
            throw ValidationException::withMessages([
                'fuec_pre_generation.range' => $e->getMessage(),
            ]);
        }

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename=fuec-preview.pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
