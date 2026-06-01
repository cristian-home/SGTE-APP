<?php

namespace App\Rules;

use App\Models\Service;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

class NoScheduleConflict implements ValidationRule
{
    public function __construct(
        protected string $field,
        protected int $fieldValue,
        protected string $plannedStartAt,
        protected string $plannedEndAt,
        protected ?int $excludeServiceId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $newStart = Carbon::parse($this->plannedStartAt);
        $newEnd = Carbon::parse($this->plannedEndAt);

        // Use the denormalized day column to limit the candidate set to a
        // 1- or 2-day window (a service may straddle midnight in some TZ),
        // then compare instants. Comparing instants makes the conflict
        // detection TZ-agnostic.
        $startDay = $newStart->copy()->toDateString();
        $endDay = $newEnd->copy()->toDateString();

        $candidateDays = array_unique([$startDay, $endDay]);
        $conflicts = Service::query()
            ->where($this->field, $this->fieldValue)
            ->where(function ($query) use ($candidateDays): void {
                foreach ($candidateDays as $day) {
                    $query->orWhereDate('service_date_local', $day);
                }
            })
            ->when($this->excludeServiceId, fn ($q) => $q->where('id', '!=', $this->excludeServiceId))
            ->get(['id', 'planned_start_at', 'planned_end_at', 'timezone']);

        foreach ($conflicts as $existing) {
            $existingStart = Carbon::parse($existing->planned_start_at);
            $existingEnd = Carbon::parse($existing->planned_end_at);

            if ($existingStart < $newEnd && $newStart < $existingEnd) {
                $label = $this->field === 'vehicle_id' ? 'vehiculo' : 'conductor';
                $tz = $existing->timezone ?: (string) config('app.operation_tz', 'America/Bogota');
                $startLabel = $existingStart->copy()->setTimezone($tz)->format('H:i');
                $endLabel = $existingEnd->copy()->setTimezone($tz)->format('H:i');
                $fail("El {$label} ya tiene un servicio asignado en este horario ({$startLabel} - {$endLabel}).");

                return;
            }
        }
    }
}
