<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Enums\VehicleStatus;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceIncident;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use App\Support\Tz;
use App\Support\VehicleLocationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Days-ahead window used to flag "por vencer" vehicle + driver
     * documents (SOAT, RTM, operation card, driver license).
     */
    private const EXPIRY_ALERT_DAYS = 30;

    /**
     * Days-ahead window used to flag contracts as "por vencer".
     * Contracts have a longer renewal lead time than SOAT/RTM/license,
     * so we surface them earlier. Mirrors CONTRACT_EXPIRY_WINDOW_DAYS
     * in resources/js/lib/document-status.ts.
     */
    private const CONTRACT_EXPIRY_ALERT_DAYS = 60;

    /**
     * Maximum rows returned in the document-alerts panel.
     */
    private const ALERTS_MAX_ROWS = 10;

    /**
     * Window for the dashboard sparklines. 14 days is short enough that
     * a sparkline renders meaningfully at 60-80px wide, long enough to
     * spot week-over-week trends.
     */
    private const TREND_WINDOW_DAYS = 14;

    /**
     * Maximum services pulled for the today mini-Gantt. Keeps the
     * payload small; the full planner lives at /gantt.
     */
    private const TODAY_SERVICES_LIMIT = 20;

    /**
     * Top-N overdue invoices surfaced in the dashboard card.
     */
    private const OVERDUE_INVOICES_LIMIT = 5;

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->hasRole(Role::DRIVER->value) && ! $user->hasRole(Role::SUPER_ADMIN->value)) {
            return redirect()->route('driver.dashboard');
        }

        $operationTz = Tz::operation();
        $today = Carbon::now($operationTz)->startOfDay();
        $expiryThreshold = $today->copy()->addDays(self::EXPIRY_ALERT_DAYS);
        $contractExpiryThreshold = $today->copy()->addDays(self::CONTRACT_EXPIRY_ALERT_DAYS);
        $todayString = $today->toDateString();

        return Inertia::render('dashboard', [
            'kpis' => [
                'vehicles' => [
                    'total' => Vehicle::count(),
                    'active' => Vehicle::where('status', VehicleStatus::Active->value)->count(),
                    'maintenance' => Vehicle::where('status', VehicleStatus::Maintenance->value)->count(),
                ],
                'drivers' => [
                    'total' => Driver::count(),
                    'active' => Driver::where('active', true)->count(),
                    'inactive' => Driver::where('active', false)->count(),
                ],
                'services_today' => [
                    'total' => Service::whereDate('service_date_local', $todayString)->count(),
                    'open' => Service::whereDate('service_date_local', $todayString)
                        ->where('service_status', ServiceStatus::Open->value)
                        ->count(),
                    'closed' => Service::whereDate('service_date_local', $todayString)
                        ->where('service_status', ServiceStatus::Closed->value)
                        ->count(),
                ],
                'incidents_today' => $this->buildIncidentsToday($operationTz, $todayString),
            ],
            'trends' => [
                'services' => $this->buildServiceTrend($todayString),
                'incidents' => $this->buildIncidentTrend($operationTz, $today),
            ],
            'todayServices' => $this->buildTodayServices($todayString),
            'activeVehicles' => $this->buildActiveVehicles($todayString),
            'overdueInvoices' => $this->buildOverdueInvoices($today),
            'documentAlerts' => $this->buildDocumentAlerts($today, $expiryThreshold, $contractExpiryThreshold),
        ]);
    }

    /**
     * Incident counts for "today" in the operation TZ. `affects_billing`
     * is the operational headline (billable surprises); `from_driver`
     * surfaces the volume of self-reported incidents (a proxy for driver
     * engagement with the preflight flow).
     *
     * @return array{total: int, affects_billing: int, from_driver: int}
     */
    private function buildIncidentsToday(string $operationTz, string $todayString): array
    {
        $start = Tz::startOfDayInTzAsUtc($todayString, $operationTz);
        $end = Tz::endOfDayInTzAsUtc($todayString, $operationTz);

        $base = ServiceIncident::query()
            ->whereBetween('reported_at', [$start, $end]);

        return [
            'total' => (clone $base)->count(),
            'affects_billing' => (clone $base)->where('affects_billing', true)->count(),
            'from_driver' => (clone $base)->where('is_driver_report', true)->count(),
        ];
    }

    /**
     * Daily count of services for the trailing TREND_WINDOW_DAYS, including
     * today. Zero-fills missing days so the sparkline renders an even
     * timeline. Bucketed in PHP from the raw `service_date_local` values
     * because the column comes back as a `date` on Postgres but as a
     * datetime string on SQLite (test env) — bucketing client-side keeps
     * the key shape consistent across drivers.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function buildServiceTrend(string $todayString): array
    {
        $start = Carbon::parse($todayString)->subDays(self::TREND_WINDOW_DAYS - 1);

        // `service_date_local` stores a date column on Postgres but a
        // string on SQLite; `whereBetween` works for both as long as
        // the bounds are date strings. We bucket in PHP so the key
        // shape (`Y-m-d`) is consistent across drivers.
        $rows = Service::query()
            ->whereBetween('service_date_local', [
                Carbon::parse($start->toDateString())->startOfDay(),
                Carbon::parse($todayString)->endOfDay(),
            ])
            ->get(['service_date_local']);

        $counts = $rows
            ->groupBy(fn (Service $service) => Carbon::parse($service->service_date_local)->toDateString())
            ->map(fn ($group) => $group->count())
            ->all();

        return $this->fillDateSeries($start, self::TREND_WINDOW_DAYS, $counts);
    }

    /**
     * Daily count of service incidents for the trailing TREND_WINDOW_DAYS,
     * projected to the operation TZ. Bucketed in PHP from the raw
     * `reported_at` UTC instants to stay portable across drivers (no
     * Postgres-specific `AT TIME ZONE` in the query).
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function buildIncidentTrend(string $operationTz, Carbon $today): array
    {
        $start = $today->copy()->subDays(self::TREND_WINDOW_DAYS - 1);
        $startUtc = Tz::startOfDayInTzAsUtc($start->toDateString(), $operationTz);
        $endUtc = Tz::endOfDayInTzAsUtc($today->toDateString(), $operationTz);

        $rawCounts = ServiceIncident::query()
            ->whereBetween('reported_at', [$startUtc, $endUtc])
            ->get(['reported_at'])
            ->groupBy(function (ServiceIncident $incident) use ($operationTz) {
                return $incident->reported_at?->setTimezone($operationTz)->toDateString();
            })
            ->map(fn ($group) => $group->count())
            ->all();

        return $this->fillDateSeries($start, self::TREND_WINDOW_DAYS, $rawCounts);
    }

    /**
     * Today's services for the mini-Gantt widget. Lean payload — just
     * what the bar position helper (`serviceBarPosition` in
     * `resources/js/pages/gantt/gantt-utils.ts`) needs: planned_start_at,
     * planned_duration, timezone, vehicle plate, status and brief origin
     * label.
     *
     * @return array<int, array{id: int, service_number: string, vehicle_plate: string|null, planned_start_at: string|null, planned_duration_min: int|null, timezone: string, status: string, origin_label: string|null}>
     */
    private function buildTodayServices(string $todayString): array
    {
        return Service::query()
            ->with(['vehicle:id,plate', 'originMunicipality:id,name'])
            ->whereDate('service_date_local', $todayString)
            ->orderBy('planned_start_at')
            ->limit(self::TODAY_SERVICES_LIMIT)
            ->get([
                'id',
                'service_number',
                'vehicle_id',
                'origin_municipality_id',
                'origin_address',
                'planned_start_at',
                'planned_end_at',
                'timezone',
                'service_status',
            ])
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'service_number' => $service->service_number,
                'vehicle_plate' => $service->vehicle?->plate,
                'planned_start_at' => $service->planned_start_at?->toIso8601String(),
                'planned_duration_min' => $service->planned_duration,
                'timezone' => $service->resolveTimezone(),
                'status' => $service->service_status?->value,
                'origin_label' => $service->originMunicipality?->name ?? $service->origin_address,
            ])
            ->values()
            ->all();
    }

    /**
     * Vehicles with an open service today, each enriched with their
     * latest known GPS position. Reuses VehicleLocationResolver so the
     * fallback rules match the full /gps/map view.
     *
     * @return array<int, array{vehicle_plate: string, service_id: int, location: array{lat: float, lng: float, recorded_at: string|null}}>
     */
    private function buildActiveVehicles(string $todayString): array
    {
        return Service::query()
            ->with('vehicle:id,plate')
            ->where('service_status', ServiceStatus::Open->value)
            ->whereDate('service_date_local', $todayString)
            ->whereNotNull('vehicle_id')
            ->orderBy('planned_start_at')
            ->get(['id', 'vehicle_id'])
            ->map(function (Service $service): ?array {
                $location = VehicleLocationResolver::latestForVehicle(
                    vehicleId: $service->vehicle_id,
                    serviceId: $service->id,
                );
                if ($location === null || $service->vehicle === null) {
                    return null;
                }

                return [
                    'vehicle_plate' => $service->vehicle->plate,
                    'service_id' => $service->id,
                    'location' => [
                        'lat' => (float) $location->latitude,
                        'lng' => (float) $location->longitude,
                        'recorded_at' => $location->recorded_at?->toIso8601String(),
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Top-N invoices stuck in `overdue`. Sorted oldest first (most days
     * since issue = most urgent to collect). The system stores no
     * explicit payment deadline so "days_since_issue" is the best proxy
     * for urgency.
     *
     * @return array<int, array{id: int, invoice_number: string|null, total_value: string, customer_name: string, days_since_issue: int}>
     */
    private function buildOverdueInvoices(Carbon $today): array
    {
        return Invoice::query()
            ->with('thirdParty:id,first_name,first_lastname,company_name,trade_name')
            ->where('payment_status', PaymentStatus::Overdue->value)
            ->orderBy('issued_at')
            ->limit(self::OVERDUE_INVOICES_LIMIT)
            ->get(['id', 'third_party_id', 'invoice_number', 'total_value', 'issued_at', 'timezone', 'payment_status'])
            ->map(function (Invoice $invoice) use ($today): array {
                $daysSinceIssue = $invoice->issued_at
                    ? max(0, (int) $today->diffInDays($invoice->issued_at, false) * -1)
                    : 0;

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'total_value' => (string) $invoice->total_value,
                    'customer_name' => $this->resolveThirdPartyName($invoice->thirdParty),
                    'days_since_issue' => $daysSinceIssue,
                ];
            })
            ->all();
    }

    /**
     * Display name for a third party — company trade/legal name for
     * companies, full name for natural persons. Matches the convention
     * used elsewhere in the UI (third-parties index, invoices index).
     */
    private function resolveThirdPartyName(?ThirdParty $tp): string
    {
        if ($tp === null) {
            return '—';
        }

        return $tp->company_name
            ?? $tp->trade_name
            ?? trim(($tp->first_name ?? '').' '.($tp->first_lastname ?? ''))
            ?: '—';
    }

    /**
     * Zero-fill a daily series so the sparkline gets a continuous
     * timeline even on days with no events. The output is always
     * `$days` entries, oldest first, ending on today.
     *
     * @param  array<string, int>  $counts
     * @return array<int, array{date: string, count: int}>
     */
    private function fillDateSeries(Carbon $start, int $days, array $counts): array
    {
        $series = [];
        $cursor = $start->copy();
        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->toDateString();
            $series[] = [
                'date' => $date,
                'count' => (int) ($counts[$date] ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * @return array<int, array{kind: 'vehicle'|'driver'|'contract', label: string, subject: string, due_date: string|null, days_remaining: int, link: string}>
     */
    private function buildDocumentAlerts(Carbon $today, Carbon $expiryThreshold, Carbon $contractExpiryThreshold): array
    {
        $expiryThresholdInstant = $expiryThreshold->copy()->utc();
        $vehicleAlerts = Vehicle::query()
            ->select(['id', 'plate', 'internal_code', 'timezone', 'soat_due_at', 'rtm_due_at', 'operation_card_due_at'])
            ->where(function ($query) use ($expiryThresholdInstant) {
                $query->whereNotNull('soat_due_at')->where('soat_due_at', '<=', $expiryThresholdInstant)
                    ->orWhereNotNull('rtm_due_at')->where('rtm_due_at', '<=', $expiryThresholdInstant)
                    ->orWhereNotNull('operation_card_due_at')->where('operation_card_due_at', '<=', $expiryThresholdInstant);
            })
            ->get()
            ->flatMap(function (Vehicle $vehicle) use ($today, $expiryThresholdInstant) {
                $fields = [
                    'soat_due_at' => 'SOAT',
                    'rtm_due_at' => 'RTM',
                    'operation_card_due_at' => 'Tarjeta de Operación',
                ];

                $rows = [];
                foreach ($fields as $column => $label) {
                    $dueAt = $vehicle->{$column};
                    if ($dueAt === null || $dueAt->greaterThan($expiryThresholdInstant)) {
                        continue;
                    }
                    $daysRemaining = (int) $today->diffInDays($dueAt, false);
                    $rows[] = [
                        'kind' => 'vehicle',
                        'label' => $label,
                        'subject' => $vehicle->plate,
                        'due_date' => $vehicle->{str_replace('_at', '_date', $column)},
                        'days_remaining' => $daysRemaining,
                        'link' => $this->vehicleAlertLink($daysRemaining),
                    ];
                }

                return $rows;
            });

        $driverAlerts = Driver::query()
            ->select(['id', 'first_name', 'first_lastname', 'timezone', 'license_due_at'])
            ->whereNotNull('license_due_at')
            ->where('license_due_at', '<=', $expiryThresholdInstant)
            ->get()
            ->map(function (Driver $driver) use ($today) {
                $daysRemaining = (int) $today->diffInDays($driver->license_due_at, false);

                return [
                    'kind' => 'driver',
                    'label' => 'Licencia',
                    'subject' => trim($driver->first_name.' '.$driver->first_lastname),
                    'due_date' => $driver->license_due_date,
                    'days_remaining' => $daysRemaining,
                    'link' => $this->driverAlertLink($daysRemaining),
                ];
            });

        $contractAlerts = Contract::query()
            ->select(['id', 'contract_number', 'end_at', 'timezone', 'active'])
            ->where('active', true)
            ->whereNotNull('end_at')
            ->where('end_at', '<=', $contractExpiryThreshold->copy()->utc())
            ->get()
            ->map(function (Contract $contract) use ($today) {
                $daysRemaining = (int) $today->diffInDays($contract->end_at, false);

                return [
                    'kind' => 'contract',
                    'label' => 'Contrato',
                    'subject' => $contract->contract_number,
                    'due_date' => $contract->end_date,
                    'days_remaining' => $daysRemaining,
                    'link' => $this->contractAlertLink($daysRemaining),
                ];
            });

        return $vehicleAlerts
            ->concat($driverAlerts)
            ->concat($contractAlerts)
            ->sortBy('days_remaining')
            ->values()
            ->take(self::ALERTS_MAX_ROWS)
            ->all();
    }

    /**
     * Build the deep-link a vehicle alert should navigate to. Already-expired
     * documents jump to the vehicles index filtered by docs_status=expired;
     * documents within the 30-day window jump to docs_status=expiring_soon.
     */
    private function vehicleAlertLink(int $daysRemaining): string
    {
        // <= 0 because license_due_at is the half-open exclusive instant
        // (next-midnight after the conventional last day): when "today
        // is the conventional last day", daysRemaining = 0 means the
        // document already lapsed at start of business today.
        return $daysRemaining <= 0
            ? '/vehicles?filter[docs_status]=expired'
            : '/vehicles?filter[docs_status]=expiring_soon';
    }

    /**
     * Build the deep-link a driver alert should navigate to. Symmetric
     * with vehicleAlertLink — already-expired licenses jump to the
     * drivers index filtered by license_status=expired; licenses within
     * the 30-day window jump to license_status=expiring_soon.
     */
    private function driverAlertLink(int $daysRemaining): string
    {
        return $daysRemaining <= 0
            ? '/drivers?filter[license_status]=expired'
            : '/drivers?filter[license_status]=expiring_soon';
    }

    /**
     * Build the deep-link a contract alert should navigate to. Symmetric
     * with vehicleAlertLink / driverAlertLink — already-expired contracts
     * jump to the contracts index filtered by contract_status=vencido;
     * contracts within the 60-day window jump to expiring_soon. The
     * backend accepts both `vencido`/`expired` and
     * `por_vencer`/`expiring_soon` as aliases for the same bucket.
     */
    private function contractAlertLink(int $daysRemaining): string
    {
        return $daysRemaining <= 0
            ? '/contracts?filter[contract_status]=vencido'
            : '/contracts?filter[contract_status]=expiring_soon';
    }
}
