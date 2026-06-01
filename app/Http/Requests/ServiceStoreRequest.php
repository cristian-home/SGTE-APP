<?php

namespace App\Http\Requests;

use App\Enums\DayStatusEnum;
use App\Enums\LicenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Enums\VehicleType;
use App\Models\Contract;
use App\Models\DayStatus;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Rules\NoScheduleConflict;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ServiceStoreRequest extends FormRequest
{
    /**
     * Tolerance, in hours, that the actual (executed) times may deviate
     * from the planned window. The actual start/end must fall within
     * [planned_start − N h, planned_end + N h]. Allows realistic early
     * departures / late finishes while catching gross data-entry errors
     * (e.g. a wrong month). Mirrored on the frontend pickers' min/max.
     */
    protected const ACTUAL_TOLERANCE_HOURS = 6;

    /**
     * License ↔ vehicle-type compatibility for Colombian public passenger transport.
     * Keys are vehicle types, values are the license categories legally authorized
     * to drive them for public passenger transport.
     *
     * @var array<string, list<string>>
     */
    protected const LICENSE_CATEGORY_MAP = [
        VehicleType::Bus->value => [LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Buseta->value => [LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Van->value => [LicenseCategory::C1->value, LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Automobile->value => [LicenseCategory::C1->value, LicenseCategory::C2->value, LicenseCategory::C3->value],
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows(Permission::CREATE_SERVICES->value);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            // The consecutive is reserved server-side and shown read-only in
            // the form. The submitted value is the reservation; the controller
            // re-guards uniqueness and re-reserves on the rare collision.
            'service_number' => ['nullable', 'string', 'max:50'],
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            // Wall-clock datetimes (Y-m-d H:i) from the form's datetime
            // pickers. The persisted source of truth is the UTC instant
            // (`planned_start_at` / `planned_end_at`) plus `service_date_local`
            // (operation-TZ day), all merged in prepareForValidation().
            'planned_start' => ['required', 'date_format:Y-m-d H:i'],
            // Optional input: when omitted it is derived from
            // planned_start + planned_duration in prepareForValidation. The
            // persisted `planned_end_at` instant below stays required.
            'planned_end' => ['nullable', 'date_format:Y-m-d H:i'],
            'service_date_local' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'planned_start_at' => ['required', 'date'],
            // Ordering (end after start) is enforced in after() against the
            // visible `planned_end` field so the error renders on the picker.
            'planned_end_at' => ['required', 'date'],
            // The city (municipality) is required on both ends — it is the
            // legally/operationally meaningful minimum (FUEC, reports). The
            // precise address + coordinates stay optional: when only the city
            // is given, prepareForValidation() fills the coordinates from the
            // municipality centroid (source 'centroid').
            'origin_municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            // When the operator fills the address text, they must also
            // confirm the location — by picking a Google Places suggestion
            // or by placing a pin on the map. A city alone needs no pin
            // (the centroid is used). We refuse to persist a free-text-only
            // address because it has no usable geographic meaning.
            'origin_coordinates' => ['required_with:origin_address', 'nullable', 'string', 'max:50', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'origin_coordinates_source' => ['required_with:origin_address', 'nullable', Rule::in(['google', 'manual', 'centroid'])],
            'origin_coordinates_accuracy' => ['nullable', 'string', 'max:20'],
            'origin_place_id' => ['nullable', 'string', 'max:255'],
            'destination_municipality_id' => ['required', 'integer', 'exists:municipalities,id'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'destination_coordinates' => ['required_with:destination_address', 'nullable', 'string', 'max:50', 'regex:/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/'],
            'destination_coordinates_source' => ['required_with:destination_address', 'nullable', Rule::in(['google', 'manual', 'centroid'])],
            'destination_coordinates_accuracy' => ['nullable', 'string', 'max:20'],
            'destination_place_id' => ['nullable', 'string', 'max:255'],
            // Input-only convenience (minutes): when `planned_end` is omitted,
            // prepareForValidation() derives planned_end_at from start +
            // duration. Not persisted — the planned duration is derived from
            // the window via the Service::planned_duration accessor.
            'planned_duration' => ['nullable', 'integer'],
            'actual_start' => ['nullable', Rule::requiredIf($this->input('service_status') === 'closed'), 'date_format:Y-m-d H:i'],
            'actual_end' => ['nullable', Rule::requiredIf($this->input('service_status') === 'closed'), 'date_format:Y-m-d H:i'],
            'actual_start_at' => ['nullable', 'date'],
            // Ordering is enforced in after() against the visible
            // `actual_end` field. Compared as instants, so a next-day end
            // (e.g. 22:00 → 05:00) validates correctly across midnight.
            'actual_end_at' => ['nullable', 'date'],
            'unit_value' => ['required', 'numeric', 'between:-9999999999.99,9999999999.99'],
            'quantity' => ['required', 'integer'],
            'billing_groups' => ['nullable', 'array'],
            'billing_groups.*' => ['string', 'max:50', 'distinct'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'service_status' => ['required', Rule::enum(ServiceStatus::class)],
            'manual_entry_justification' => ['nullable', 'string', 'min:10', 'max:500'],
            // Q4 / bug-log:BUG-03 — admin late-add justification on EJECUTADO day.
            'justification' => ['nullable', 'string', 'min:10', 'max:500'],
            // Q5 / bug-log:BUG-01 — caller opts into auto-creation of a
            // GEN-NNNN-YYYY generic contract when the picked contract does
            // not cover the service date. Boolean flag, not persisted.
            'create_generic_contract' => ['nullable', 'boolean'],
        ];

        if ($this->filled('vehicle_id') && $this->filled('planned_start_at') && $this->filled('planned_end_at')) {
            $rules['vehicle_id'][] = new NoScheduleConflict(
                'vehicle_id',
                (int) $this->input('vehicle_id'),
                $this->input('planned_start_at'),
                $this->input('planned_end_at'),
                $this->excludeServiceId(),
            );
        }

        if ($this->filled('driver_id') && $this->filled('planned_start_at') && $this->filled('planned_end_at')) {
            $rules['driver_id'][] = new NoScheduleConflict(
                'driver_id',
                (int) $this->input('driver_id'),
                $this->input('planned_start_at'),
                $this->input('planned_end_at'),
                $this->excludeServiceId(),
            );
        }

        return $rules;
    }

    /**
     * Additional validation after standard rules pass.
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $this->validateExecutedDayRestriction($validator);
                $this->validateContractCoversDate($validator);
                $this->validateDriverRequired($validator);
                $this->validateVehicleDocumentsNotExpired($validator);
                $this->validateDriverLicense($validator);
                $this->validateRetroactiveEntry($validator);
                $this->validatePlannedStartNotPast($validator);
                $this->validateScheduleOrder($validator);
                $this->validateActualWithinTolerance($validator);
            },
        ];
    }

    /**
     * Bound the actual (executed) times to the planned window plus a
     * tolerance (see ACTUAL_TOLERANCE_HOURS): each actual instant must fall
     * within [planned_start − N h, planned_end + N h]. The actual start may
     * legitimately precede the planned start (early departure) — it just
     * cannot drift further than the tolerance. Errors are keyed to the
     * visible wall-clock fields so they render on the pickers.
     */
    protected function validateActualWithinTolerance($validator): void
    {
        $plannedStart = $this->input('planned_start_at');
        $plannedEnd = $this->input('planned_end_at');

        if (! is_string($plannedStart) || $plannedStart === '' || ! is_string($plannedEnd) || $plannedEnd === '') {
            return;
        }

        try {
            $lower = CarbonImmutable::parse($plannedStart)->utc()->subHours(self::ACTUAL_TOLERANCE_HOURS);
            $upper = CarbonImmutable::parse($plannedEnd)->utc()->addHours(self::ACTUAL_TOLERANCE_HOURS);
        } catch (\Exception) {
            return;
        }

        $checks = [
            ['actual_start_at', 'actual_start', 'El inicio real está fuera de la tolerancia de '.self::ACTUAL_TOLERANCE_HOURS.' h respecto al horario planificado.'],
            ['actual_end_at', 'actual_end', 'El fin real está fuera de la tolerancia de '.self::ACTUAL_TOLERANCE_HOURS.' h respecto al horario planificado.'],
        ];

        foreach ($checks as [$key, $field, $message]) {
            $value = $this->input($key);
            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                $instant = CarbonImmutable::parse($value)->utc();
            } catch (\Exception) {
                continue;
            }

            if ($instant->lt($lower) || $instant->gt($upper)) {
                $validator->errors()->add($field, $message);
            }
        }
    }

    /**
     * Enforce end-after-start for both the planned and actual windows,
     * comparing UTC instants (so a window crossing midnight is valid) and
     * keying errors to the visible wall-clock fields so they render on the
     * pickers.
     */
    protected function validateScheduleOrder($validator): void
    {
        $pairs = [
            ['planned_start_at', 'planned_end_at', 'planned_end', 'El fin planificado debe ser posterior al inicio.'],
            ['actual_start_at', 'actual_end_at', 'actual_end', 'El fin real debe ser posterior al inicio.'],
        ];

        foreach ($pairs as [$startKey, $endKey, $errorField, $message]) {
            $start = $this->input($startKey);
            $end = $this->input($endKey);

            if (! is_string($start) || $start === '' || ! is_string($end) || $end === '') {
                continue;
            }

            try {
                $startAt = CarbonImmutable::parse($start)->utc();
                $endAt = CarbonImmutable::parse($end)->utc();
            } catch (\Exception) {
                continue;
            }

            if ($endAt->lte($startAt)) {
                $validator->errors()->add($errorField, $message);
            }
        }
    }

    /**
     * Block scheduling an OPEN service in the past. Only on create (edits
     * to existing services may legitimately touch past dates) and only for
     * open services — a CLOSED past-dated service is a retroactive record
     * governed by validateRetroactiveEntry(), not a forward plan, so it is
     * intentionally allowed here.
     */
    protected function validatePlannedStartNotPast($validator): void
    {
        if ($this->route('service') !== null) {
            return;
        }

        if ($this->input('service_status') === ServiceStatus::Closed->value) {
            return;
        }

        $plannedStart = $this->input('planned_start_at');
        if (! is_string($plannedStart) || $plannedStart === '') {
            return;
        }

        $timezone = $this->resolveServiceTimezone();

        try {
            $startDate = CarbonImmutable::parse($plannedStart)->setTimezone($timezone)->toDateString();
        } catch (\Exception) {
            return;
        }

        // Date-granular per requirement ("fecha anterior a hoy"): a service
        // scheduled for today at any time is fine; only an earlier calendar
        // day in the service's timezone is rejected.
        if ($startDate < CarbonImmutable::now($timezone)->toDateString()) {
            $validator->errors()->add(
                'planned_start',
                'No se puede planear un servicio en una fecha anterior a hoy.',
            );
        }
    }

    /**
     * REQ-009 provenance gate on create:
     *
     * - If service_date >= today AND service_status = closed, reject.
     *   A service that hasn't happened yet cannot be Cerrado; the
     *   driver workflow (confirmStart → confirmEnd) is the only legit
     *   path to close.
     * - If service_date < today AND service_status = closed, accept
     *   the create but require manual_entry_justification (the Store
     *   request already enforces min:10). This distinguishes a back-
     *   filled historical record from a shortcut around the driver
     *   workflow; the controller tags the activity_log entry with
     *   source=retroactive_entry so /audit-log can filter it.
     *
     * Only runs on create — ServiceUpdateRequest inherits rules() but
     * overrides after() and never calls this method.
     */
    protected function validateRetroactiveEntry($validator): void
    {
        // Update flows never go through this gate. Edits to an existing
        // service — including flipping status to Closed on an open past-
        // date row — are governed by ServiceUpdateRequest and the
        // executed-day justification already in place there.
        if ($this->route('service') !== null) {
            return;
        }

        if (! $this->filled('service_date_local') || ! $this->filled('service_status')) {
            return;
        }

        $status = $this->input('service_status');
        if ($status !== ServiceStatus::Closed->value) {
            return;
        }

        $today = Carbon::now($this->resolveServiceTimezone())->toDateString();
        $serviceDateString = (string) $this->input('service_date_local');

        if ($serviceDateString >= $today) {
            $validator->errors()->add(
                'service_status',
                'Un servicio con fecha hoy o futura no puede crearse en estado Cerrado. Use el flujo del conductor para cerrar servicios ejecutados.',
            );

            return;
        }

        $justification = trim((string) $this->input('manual_entry_justification'));
        if ($justification === '') {
            $validator->errors()->add(
                'manual_entry_justification',
                'La justificación es obligatoria al registrar retroactivamente un servicio cerrado.',
            );
        }
    }

    /**
     * Q4 / bug-log:BUG-03 — Admin and Super Admin may late-add a service
     * on an EJECUTADO day when they supply a `justification` (10–500 chars).
     * Other roles are rejected. The day's status remains EJECUTADO; the
     * controller is responsible for tagging the activity log.
     */
    protected function validateExecutedDayRestriction($validator): void
    {
        if (! $this->filled('service_date_local')) {
            return;
        }

        $dayStatus = DayStatus::whereDate('date', $this->input('service_date_local'))->first();

        if ($dayStatus?->status !== DayStatusEnum::Executed) {
            return;
        }

        $user = $this->user();

        if (! $user || ! $user->hasAnyRole([Role::ADMIN->value, Role::SUPER_ADMIN->value])) {
            $validator->errors()->add('service_date', 'No se pueden crear servicios en un día ejecutado.');

            return;
        }

        $justification = trim((string) $this->input('justification'));
        if ($justification === '') {
            $validator->errors()->add('justification', 'La justificación es obligatoria para agregar un servicio a un día ejecutado.');

            return;
        }

        if (mb_strlen($justification) < 10 || mb_strlen($justification) > 500) {
            $validator->errors()->add('justification', 'La justificación debe tener entre 10 y 500 caracteres.');
        }
    }

    /**
     * Q5 / bug-log:BUG-01 — the controller may auto-create a GEN-NNNN-YYYY
     * generic contract when the caller opts in via `create_generic_contract`.
     * In that mode we skip the out-of-window rejection here so the controller
     * has a chance to substitute. Without the opt-in, the strict rejection
     * stays in place to keep operators from silently mis-assigning contracts.
     */
    protected function validateContractCoversDate($validator): void
    {
        if (! $this->filled('contract_id') || ! $this->filled('service_date_local')) {
            return;
        }

        $contract = Contract::find($this->input('contract_id'));

        if (! $contract) {
            return;
        }

        if (! $contract->active) {
            $validator->errors()->add('contract_id', 'El contrato seleccionado no esta activo.');
        }

        // Compare instants: the contract is active when its half-open
        // [start_at, end_at) interval contains the service's planned
        // start. F-002 fix — string-lex on the JSON-serialized dates was
        // dropping contracts whose `start_at` matched the service day.
        $plannedStart = $this->input('planned_start_at');
        if (! is_string($plannedStart) || $plannedStart === '') {
            return;
        }

        try {
            $instant = \Carbon\CarbonImmutable::parse($plannedStart)->utc();
        } catch (\Exception) {
            return;
        }

        if ($contract->start_at === null || $contract->end_at === null) {
            return;
        }

        if ($instant->lt($contract->start_at) || $instant->gte($contract->end_at)) {
            if ($this->boolean('create_generic_contract')) {
                // Controller will auto-bridge to a generic contract.
                return;
            }

            $validator->errors()->add('contract_id', 'La fecha del servicio no esta dentro del rango del contrato.');
        }
    }

    protected function validateDriverRequired($validator): void
    {
        if (! $this->filled('vehicle_id')) {
            return;
        }

        $vehicle = Vehicle::find($this->input('vehicle_id'));

        if (! $vehicle) {
            return;
        }

        if (! $vehicle->is_third_party && ! $this->filled('driver_id')) {
            $validator->errors()->add('driver_id', 'El conductor es requerido para vehiculos propios.');
        }
    }

    /**
     * REQ-004 AC 3-5: block scheduling a vehicle whose SOAT, RTM, or
     * tarjeta de operación is expired on the service date.
     */
    protected function validateVehicleDocumentsNotExpired($validator): void
    {
        if (! $this->filled('vehicle_id') || ! $this->filled('service_date_local')) {
            return;
        }

        $vehicle = Vehicle::find($this->input('vehicle_id'));

        if (! $vehicle) {
            return;
        }

        $plannedStart = $this->input('planned_start_at');
        if (! is_string($plannedStart) || $plannedStart === '') {
            return;
        }
        try {
            $instant = CarbonImmutable::parse($plannedStart)->utc();
        } catch (\Exception) {
            return;
        }

        $documents = [
            'soat_due_at' => 'SOAT',
            'rtm_due_at' => 'RTM',
            'operation_card_due_at' => 'Tarjeta de Operación',
        ];

        foreach ($documents as $column => $label) {
            $dueAt = $vehicle->{$column};

            if ($dueAt === null) {
                $validator->errors()->add(
                    'vehicle_id',
                    "El vehiculo no tiene registrado el {$label}."
                );

                continue;
            }

            if ($instant->gte($dueAt)) {
                $visible = $vehicle->{str_replace('_at', '_date', $column)};
                $validator->errors()->add(
                    'vehicle_id',
                    "El {$label} del vehiculo esta vencido ({$visible})."
                );
            }
        }
    }

    /**
     * REQ-003 AC 5 / REQ-005 AC 2: block scheduling a driver whose
     * license is expired, whose category is incompatible with the
     * vehicle type, or who lacks active social security.
     *
     * Skipped when the vehicle is third-party (driver_id is nulled in
     * prepareForValidation for those).
     */
    protected function validateDriverLicense($validator): void
    {
        if (! $this->filled('driver_id') || ! $this->filled('service_date_local')) {
            return;
        }

        $driver = Driver::find($this->input('driver_id'));

        if (! $driver) {
            return;
        }

        $plannedStart = $this->input('planned_start_at');
        $instant = null;
        if (is_string($plannedStart) && $plannedStart !== '') {
            try {
                $instant = CarbonImmutable::parse($plannedStart)->utc();
            } catch (\Exception) {
                $instant = null;
            }
        }

        if ($driver->license_due_at === null) {
            $validator->errors()->add('driver_id', 'El conductor no tiene registrada la fecha de vencimiento de la licencia.');
        } elseif ($instant !== null && $instant->gte($driver->license_due_at)) {
            $validator->errors()->add(
                'driver_id',
                "La licencia del conductor esta vencida ({$driver->license_due_date})."
            );
        }

        if ($driver->has_social_security === false) {
            $validator->errors()->add('driver_id', 'El conductor no tiene seguridad social activa.');
        }

        if ($this->filled('vehicle_id') && $driver->license_category !== null) {
            $vehicle = Vehicle::find($this->input('vehicle_id'));

            if ($vehicle && $vehicle->type !== null) {
                $vehicleType = $vehicle->type instanceof VehicleType ? $vehicle->type->value : (string) $vehicle->type;
                $allowed = self::LICENSE_CATEGORY_MAP[$vehicleType] ?? [];
                $driverCategory = $driver->license_category instanceof LicenseCategory
                    ? $driver->license_category->value
                    : (string) $driver->license_category;

                if ($allowed !== [] && ! in_array($driverCategory, $allowed, true)) {
                    $validator->errors()->add(
                        'driver_id',
                        "La categoria de licencia {$driverCategory} del conductor no es compatible con el tipo de vehiculo."
                    );
                }
            }
        }
    }

    protected function excludeServiceId(): ?int
    {
        return null;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('vehicle_id')) {
            $vehicle = Vehicle::find($this->input('vehicle_id'));

            if ($vehicle && $vehicle->is_third_party) {
                $this->merge(['driver_id' => null]);
            }
        }

        $timezone = $this->resolveServiceTimezone();
        $this->merge(['timezone' => $timezone]);

        // Project each wall-clock datetime (Y-m-d H:i) in the service's TZ to
        // a UTC instant. This mirrors the iCalendar "instant + IANA TZ"
        // pattern: persistence is universal, presentation is event-TZ. Each
        // datetime carries its own day, so a window that crosses midnight
        // (e.g. 22:00 → 05:00) round-trips correctly.
        $plannedStartAt = $this->projectWallClock($this->input('planned_start'), $timezone);
        if ($plannedStartAt !== null) {
            $serviceDate = $plannedStartAt->setTimezone($timezone)->format('Y-m-d');
            $this->merge([
                'planned_start_at' => $plannedStartAt->utc()->toIso8601String(),
                'service_date' => $serviceDate,
                'service_date_local' => $serviceDate,
            ]);
        }

        $plannedEndAt = $this->projectWallClock($this->input('planned_end'), $timezone);
        // Fall back to start + duration when the caller supplies a duration
        // but no explicit end datetime.
        if ($plannedEndAt === null && $plannedStartAt !== null && is_numeric($this->input('planned_duration'))) {
            $plannedEndAt = $plannedStartAt->addMinutes((int) $this->input('planned_duration'));
        }
        if ($plannedEndAt !== null) {
            $this->merge(['planned_end_at' => $plannedEndAt->utc()->toIso8601String()]);
        }

        $this->mergeActualInstants($timezone);

        $this->mergeCentroidFallback();

        $this->mergeNormalizedBillingGroups();
    }

    /**
     * City-only fallback: when a side has a municipality but no precise
     * location (no address and no coordinates), fill the coordinates from
     * the municipality centroid and tag the source as 'centroid'. This keeps
     * the "municipality ⇒ coordinates" invariant satisfied without forcing
     * the operator to drop a pin. Sides where the operator typed an address
     * are left untouched (the required_with rule still demands a real pin).
     */
    protected function mergeCentroidFallback(): void
    {
        foreach (['origin', 'destination'] as $side) {
            $municipalityId = $this->input("{$side}_municipality_id");
            $address = trim((string) $this->input("{$side}_address"));
            $coordinates = trim((string) $this->input("{$side}_coordinates"));

            if (! is_numeric($municipalityId) || $address !== '' || $coordinates !== '') {
                continue;
            }

            $municipality = \App\Models\Municipality::find($municipalityId);
            if (! $municipality || $municipality->latitude === null || $municipality->longitude === null) {
                continue;
            }

            $this->merge([
                "{$side}_coordinates" => "{$municipality->latitude},{$municipality->longitude}",
                "{$side}_coordinates_source" => 'centroid',
                "{$side}_coordinates_accuracy" => null,
                "{$side}_place_id" => null,
            ]);
        }
    }

    /**
     * Parse a wall-clock 'Y-m-d H:i' string as an instant in the given TZ.
     * Returns null when empty or unparseable (per-field rules then surface
     * the error).
     */
    protected function projectWallClock(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $instant = CarbonImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16), $timezone);
        } catch (\Exception) {
            return null;
        }

        return $instant === false ? null : $instant;
    }

    /**
     * Normalize free-text billing-group tags: trim each entry, drop
     * empties, and dedupe exact matches (case-sensitive). Preserves
     * capitalization so tags like "AC01" round-trip intact.
     */
    protected function mergeNormalizedBillingGroups(): void
    {
        $raw = $this->input('billing_groups');
        if (! is_array($raw)) {
            return;
        }

        $clean = [];
        foreach ($raw as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $trimmed = trim($tag);
            if ($trimmed === '' || in_array($trimmed, $clean, true)) {
                continue;
            }
            $clean[] = $trimmed;
        }

        $this->merge(['billing_groups' => $clean]);
    }

    /**
     * Project optional wall-clock actual_* datetimes into UTC instants. Each
     * datetime carries its own day, so the start and end may legitimately
     * fall on different calendar days.
     */
    protected function mergeActualInstants(string $timezone): void
    {
        foreach (['actual_start' => 'actual_start_at', 'actual_end' => 'actual_end_at'] as $wallclock => $instant) {
            $value = $this->input($wallclock);
            if (! is_string($value) || trim($value) === '') {
                $this->merge([$instant => null]);

                continue;
            }

            $projected = $this->projectWallClock($value, $timezone);
            if ($projected !== null) {
                $this->merge([$instant => $projected->utc()->toIso8601String()]);
            }
        }
    }

    /**
     * Resolve the service's IANA timezone in priority order:
     * request body → selected contract → app operation TZ.
     */
    protected function resolveServiceTimezone(): string
    {
        $requested = $this->input('timezone');
        if (is_string($requested) && $requested !== '' && in_array($requested, timezone_identifiers_list(), true)) {
            return $requested;
        }

        if ($this->filled('contract_id')) {
            $contract = Contract::find($this->input('contract_id'));
            $contractTz = is_object($contract) && property_exists($contract, 'timezone') ? $contract->timezone : null;
            if (is_string($contractTz) && $contractTz !== '') {
                return $contractTz;
            }
        }

        return (string) config('app.operation_tz', 'America/Bogota');
    }
}
