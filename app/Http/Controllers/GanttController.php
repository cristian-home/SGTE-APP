<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\VehicleStatus;
use App\Models\DayStatus;
use App\Models\Service;
use App\Models\Vehicle;
use App\Support\ServiceDocumentChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GanttController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_SERVICES->value);

        $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        // Default to "today" in the operation timezone, not UTC. Between
        // 19:00 and 24:00 Bogotá the UTC day is already tomorrow, so a
        // UTC-anchored default would surface the wrong calendar day on
        // Gantt at the end of the operator's shift.
        $operationTz = (string) config('app.operation_tz', 'America/Bogota');
        $date = $request->input('date', Carbon::now($operationTz)->toDateString());

        $vehicles = Vehicle::query()
            ->where('status', VehicleStatus::Active)
            ->with([
                'thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
                'municipality:id,name',
            ])
            ->select([
                'id', 'plate', 'internal_code', 'is_third_party', 'third_party_id',
                'municipality_id', 'timezone', 'soat_due_at', 'rtm_due_at', 'operation_card_due_at',
            ])
            ->orderBy('plate')
            ->get()
            ->map(function (Vehicle $vehicle) use ($date, $operationTz): array {
                // REQ-004: annotate vehicles whose legal documents have
                // expired on the service date. The frontend renders the
                // row disabled and the service form blocks creation
                // (ServiceStoreRequest::validateVehicleDocumentsNotExpired).
                $expiredDocuments = [];
                $documents = [
                    'soat_due_at' => 'SOAT',
                    'rtm_due_at' => 'RTM',
                    'operation_card_due_at' => 'Tarjeta de Operación',
                ];

                $dayInstant = \App\Support\Tz::startOfDayInTzAsUtc($date, $operationTz);

                foreach ($documents as $column => $label) {
                    $dueAt = $vehicle->{$column};
                    if ($dueAt === null) {
                        $expiredDocuments[] = $label;

                        continue;
                    }
                    if ($dueAt->lessThanOrEqualTo($dayInstant)) {
                        $expiredDocuments[] = $label;
                    }
                }

                return [
                    ...$vehicle->toArray(),
                    'blocked' => $expiredDocuments !== [],
                    'expired_documents' => $expiredDocuments,
                ];
            });

        // Anchor "service date" in the operation TZ. service_date_local
        // already projects the wall-clock day in the service's own TZ, so
        // the comparison is straight-forward; the Carbon instance is only
        // used by the document-check helpers below.
        $serviceDateCarbon = Carbon::parse($date, $operationTz);

        $services = Service::query()
            ->whereDate('service_date_local', $date)
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->with([
                'vehicle:id,plate,vehicle_type_id,is_third_party,timezone,soat_due_at,rtm_due_at,operation_card_due_at',
                'vehicle.vehicleType:id,allowed_license_categories',
                'driver:id,first_name,first_lastname,license_category,timezone,license_due_at,has_social_security',
                'contract:id,contract_number,third_party_id',
                'contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
            ])
            ->get()
            ->map(function (Service $service) use ($serviceDateCarbon): array {
                // REQ-004 / REQ-005 render-time re-check: flag services
                // whose assigned vehicle or driver has paperwork expired
                // as-of service_date. Gantt greys the bar and shows the
                // reasons in the tooltip.
                $reasons = [];

                if ($service->vehicle) {
                    $reasons = array_merge(
                        $reasons,
                        ServiceDocumentChecks::vehicleDocumentsValid($service->vehicle, $serviceDateCarbon),
                    );
                }

                if ($service->driver && $service->vehicle) {
                    $reasons = array_merge(
                        $reasons,
                        ServiceDocumentChecks::driverLicenseValid($service->driver, $service->vehicle, $serviceDateCarbon),
                    );
                }

                return [
                    ...$service->toArray(),
                    'blocked' => $reasons !== [],
                    'blocked_reasons' => $reasons,
                ];
            });

        $dayStatus = DayStatus::whereDate('date', $date)
            ->with('executor:id,name')
            ->first();

        // Infinite-scroll fetch path: the client `useGanttDays` hook
        // pulls services for adjacent days as the operator scrolls.
        // Vehicles stay client-cached from the SSR payload — only the
        // date-scoped services need to refresh.
        if ($request->wantsJson()) {
            return response()->json([
                'date' => $date,
                'services' => $services,
                'dayStatus' => $dayStatus,
            ]);
        }

        return Inertia::render('gantt/index', [
            'vehicles' => $vehicles,
            'services' => $services,
            'dayStatus' => $dayStatus,
            'date' => $date,
            'canCreateServices' => Gate::allows(Permission::CREATE_SERVICES->value),
        ]);
    }
}
