<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\ServiceStatus;
use App\Models\DayStatus;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DaySummaryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize(Permission::VIEW_DAY_SUMMARY->value);

        $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $operationTz = (string) config('app.operation_tz', 'America/Bogota');
        $date = $request->query('date', Carbon::now($operationTz)->toDateString());

        $services = Service::whereDate('service_date_local', $date)
            ->whereNull('deleted_at')
            ->with([
                'vehicle:id,plate,is_third_party,third_party_id',
                'vehicle.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number,third_party_id',
                'contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
            ])
            ->withCount('serviceIncidents')
            // Two extra aggregates for the "Recargo novedades" column:
            // count + sum of additional_value across billing-affecting
            // incidents only. Uses Eloquent's withCount / withSum
            // sub-selects so it stays one round-trip.
            ->withCount(['serviceIncidents as billing_incidents_count' => fn ($q) => $q->where('affects_billing', true)])
            ->withSum(
                ['serviceIncidents as billing_impact_amount' => fn ($q) => $q->where('affects_billing', true)],
                'additional_value',
            )
            ->orderBy('planned_start_at')
            ->get();

        $dayStatus = DayStatus::whereDate('date', $date)->with('executor:id,name')->first();

        $summary = [
            'total' => $services->count(),
            'closed' => $services->where('service_status', ServiceStatus::Closed)->count(),
            'open' => $services->where('service_status', ServiceStatus::Open)->count(),
            'with_incidents' => $services->where('service_incidents_count', '>', 0)->count(),
            'third_party' => $services->filter(fn ($s) => $s->vehicle?->is_third_party)->count(),
            'pending_reassignment' => $services->filter(
                fn ($s) => $s->driver_declined_at !== null && $s->service_status === ServiceStatus::Open,
            )->count(),
            'billing_impact_total' => (float) $services->sum(fn ($s) => (float) ($s->billing_impact_amount ?? 0)),
        ];

        return Inertia::render('day-summary/index', [
            'services' => $services,
            'dayStatus' => $dayStatus,
            'summary' => $summary,
            'date' => $date,
            'canExecuteDay' => Gate::allows(Permission::EXECUTE_DAY->value),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(Permission::VIEW_DAY_SUMMARY->value);

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $date = $request->query('date');

        $services = Service::whereDate('service_date_local', $date)
            ->whereNull('deleted_at')
            ->with([
                'vehicle:id,plate,is_third_party,third_party_id',
                'vehicle.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
                'driver:id,first_name,first_lastname',
                'contract:id,contract_number,third_party_id',
                'contract.thirdParty:id,company_name,first_name,first_lastname,is_natural_person',
            ])
            ->withCount('serviceIncidents')
            ->withSum(
                ['serviceIncidents as billing_impact_amount' => fn ($q) => $q->where('affects_billing', true)],
                'additional_value',
            )
            ->orderBy('planned_start_at')
            ->get();

        return response()->streamDownload(function () use ($services) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['Consecutivo', 'Placa', 'Conductor/Proveedor', 'Hora Inicio', 'Hora Fin', 'Duración (min)', 'Cliente', 'Estado', 'Valor del servicio', 'Novedades', 'Recargo novedades', 'Valor Unitario', 'Cantidad', 'Forma de Pago', 'Grupo Facturación', 'Total']);
            foreach ($services as $service) {
                $driverOrProvider = $service->vehicle?->is_third_party
                    ? ($service->vehicle?->thirdParty?->company_name ?? $service->vehicle?->thirdParty?->first_name.' '.$service->vehicle?->thirdParty?->first_lastname)
                    : ($service->driver?->first_name.' '.$service->driver?->first_lastname);

                $client = $service->contract?->thirdParty?->company_name
                    ?? ($service->contract?->thirdParty?->first_name.' '.$service->contract?->thirdParty?->first_lastname);

                $serviceValue = (float) $service->unit_value * (int) $service->quantity;
                $billingImpact = (float) ($service->billing_impact_amount ?? 0);
                $total = $serviceValue + $billingImpact;

                fputcsv($handle, [
                    $service->service_number,
                    $service->vehicle?->plate ?? '',
                    $driverOrProvider ?? '',
                    $service->planned_start_local ?? '',
                    $service->actual_end_local ?? '',
                    $service->planned_duration,
                    $client ?? '',
                    $service->service_status->value === 'closed' ? 'Cerrado' : 'Abierto',
                    number_format($serviceValue, 2, '.', ''),
                    $service->service_incidents_count,
                    number_format($billingImpact, 2, '.', ''),
                    $service->unit_value,
                    $service->quantity,
                    $service->payment_method->value,
                    implode(', ', $service->billing_groups ?? []),
                    number_format($total, 2, '.', ''),
                ]);
            }
            fclose($handle);
        }, "resumen-dia-{$date}.csv", ['Content-Type' => 'text/csv']);
    }
}
