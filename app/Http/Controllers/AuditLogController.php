<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Contract;
use App\Models\DayStatus;
use App\Models\DocumentType;
use App\Models\Driver;
use App\Models\Eps;
use App\Models\Fuec;
use App\Models\IncidentType;
use App\Models\Invoice;
use App\Models\PensionFund;
use App\Models\Service;
use App\Models\ServiceIncident;
use App\Models\SeveranceFund;
use App\Models\ThirdParty;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AuditLogController extends Controller
{
    /**
     * Map every `subject_type` class string that the application logs
     * activity on to a human-readable Spanish label. Used by the
     * index filter bar and the detail sheet's Entidad column.
     *
     * Keep this list in lock-step with the set of models using the
     * `LogsActivity` trait; missing entries fall back to
     * `class_basename`.
     */
    private const SUBJECT_TYPE_LABELS = [
        Service::class => 'Servicio',
        Invoice::class => 'Factura',
        Contract::class => 'Contrato',
        ServiceIncident::class => 'Novedad',
        DayStatus::class => 'Día',
        Vehicle::class => 'Vehículo',
        Driver::class => 'Conductor',
        ThirdParty::class => 'Tercero',
        User::class => 'Usuario',
        Fuec::class => 'FUEC',
        VehicleLocation::class => 'Ubicación',
        IncidentType::class => 'Tipo de Novedad',
        DocumentType::class => 'Tipo de Documento',
        Eps::class => 'EPS',
        PensionFund::class => 'Fondo de Pensiones',
        SeveranceFund::class => 'Fondo de Cesantías',
    ];

    /**
     * How many recent activity rows to scan when computing the
     * `subjectTypes` filter options. Keeps the SELECT DISTINCT cheap
     * while still surfacing long-tail models that appear occasionally.
     */
    private const SUBJECT_TYPES_SCAN_WINDOW = 1000;

    public function index(Request $request): Response|JsonResponse
    {
        Gate::authorize(Permission::VIEW_AUDIT_LOG->value);

        $activities = QueryBuilder::for(Activity::class)
            ->with(['causer:id,name,email'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value): void {
                    $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                    if ($value === '') {
                        return;
                    }
                    $needle = '%'.mb_strtolower($value).'%';
                    $query->where(function (Builder $q) use ($needle): void {
                        $q->whereRaw('lower(description) like ?', [$needle])
                            ->orWhereRaw('lower(event) like ?', [$needle]);
                    });
                }),
                AllowedFilter::exact('log_name'),
                AllowedFilter::exact('subject_type'),
                AllowedFilter::exact('causer_id'),
                AllowedFilter::exact('event'),
                AllowedFilter::callback('created_from', function (Builder $query, $value): void {
                    $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                    if ($value === '') {
                        return;
                    }
                    $query->whereDate('created_at', '>=', $value);
                }),
                AllowedFilter::callback('created_to', function (Builder $query, $value): void {
                    $value = is_array($value) ? ($value[0] ?? '') : (string) $value;
                    if ($value === '') {
                        return;
                    }
                    $query->whereDate('created_at', '<=', $value);
                }),
            ])
            ->allowedSorts(['created_at', 'log_name', 'event'])
            ->defaultSort('-created_at', '-id')
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (Activity $activity): array => $this->projectActivity($activity));

        if ($request->wantsJson()) {
            return response()->json($activities);
        }

        return Inertia::render('audit-log/index', [
            'activities' => $activities,
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'subjectTypes' => $this->subjectTypeOptions(),
        ]);
    }

    /**
     * Project a single activity row into the shape the Inertia page
     * consumes: minimal causer, raw `subject_type` (the frontend
     * resolves the label), and the full `properties` bag plus the
     * canonical `attributes` / `old` sub-keys exposed as top-level
     * `attributes` / `old_attributes` for the diff card.
     *
     * @return array<string, mixed>
     */
    private function projectActivity(Activity $activity): array
    {
        /** @var Collection<string, mixed> $properties */
        $properties = $activity->properties ?? collect();
        // laravel-activitylog v5 stores the attribute diff in `attribute_changes`;
        // v4 (and rows written before the upgrade) kept it inside `properties`.
        // Prefer the v5 location and fall back to `properties` for legacy rows.
        /** @var Collection<string, mixed> $changes */
        $changes = $activity->attribute_changes ?? collect();

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'description' => $activity->description,
            'event' => $activity->event,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'causer' => $activity->causer ? [
                'id' => $activity->causer->id,
                'name' => $activity->causer->name,
                'email' => $activity->causer->email,
            ] : null,
            'created_at' => $activity->created_at?->toIso8601String(),
            'properties' => $properties->toArray(),
            'attributes' => (array) ($changes->get('attributes') ?? $properties->get('attributes', [])),
            'old_attributes' => (array) ($changes->get('old') ?? $properties->get('old', [])),
        ];
    }

    /**
     * Compute the distinct `subject_type` values appearing in the
     * last `SUBJECT_TYPES_SCAN_WINDOW` activity rows and map each to
     * its Spanish label. Returns a plain array ordered by label
     * ascending (case-insensitive) so Inertia serializes it as a
     * JSON array.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function subjectTypeOptions(): array
    {
        $recentTypes = Activity::query()
            ->orderByDesc('id')
            ->limit(self::SUBJECT_TYPES_SCAN_WINDOW)
            ->whereNotNull('subject_type')
            ->pluck('subject_type')
            ->unique()
            ->values();

        $options = $recentTypes
            ->map(fn (string $type): array => [
                'value' => $type,
                'label' => self::SUBJECT_TYPE_LABELS[$type] ?? class_basename($type),
            ])
            ->sortBy(fn (array $option): string => mb_strtolower($option['label']))
            ->values()
            ->all();

        return $options;
    }
}
