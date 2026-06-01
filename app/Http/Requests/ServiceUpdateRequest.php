<?php

namespace App\Http\Requests;

use App\Enums\DayStatusEnum;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Models\DayStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ServiceUpdateRequest extends ServiceStoreRequest
{
    protected function excludeServiceId(): ?int
    {
        return $this->route('service')->id;
    }

    /**
     * REQ-009 service_status transition invariant
     * (service-reopen-actual-time-invariant):
     *
     * - Closed → Open: clear actual_end / actual_end_at,
     *   preserve actual_start / actual_start_at.
     *   The service is "resumable" — it started but hasn't finished yet.
     * - Open → Closed: requires both actual_* datetime fields (handled
     *   by the existing `required_if:service_status,closed` rule on
     *   both columns inherited from ServiceStoreRequest).
     * - Open → Open or Closed → Closed: no-op on the time fields.
     *
     * We merge the null BEFORE validation so the `required_if` rule
     * against the incoming `service_status = open` is satisfied, and
     * the Service model persists the cleared value.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $service = $this->route('service');

        if (! $service) {
            return;
        }

        $currentStatus = $service->service_status instanceof ServiceStatus
            ? $service->service_status->value
            : (string) $service->service_status;
        $incomingStatus = $this->input('service_status');

        if ($currentStatus === ServiceStatus::Closed->value
            && $incomingStatus === ServiceStatus::Open->value
        ) {
            $this->merge([
                'actual_end' => null,
                'actual_end_at' => null,
            ]);
        }
    }

    public function authorize(): bool
    {
        $service = $this->route('service');
        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        if ($dayStatus?->status === DayStatusEnum::Executed) {
            $user = $this->user();

            if ($user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN])) {
                return true;
            }

            if ($user->can('services.update-executed')) {
                return true;
            }

            return false;
        }

        return Gate::allows(Permission::UPDATE_PROJECTED_SERVICES->value);
    }

    public function rules(): array
    {
        $service = $this->route('service');
        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        if ($dayStatus?->status !== DayStatusEnum::Executed) {
            return parent::rules();
        }

        $user = $this->user();

        if ($user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN])) {
            $rules = parent::rules();
            $rules['justification'] = ['required', 'string', 'min:10', 'max:500'];

            return $rules;
        }

        // Accounting: billing fields only
        return [
            'billing_groups' => ['nullable', 'array'],
            'billing_groups.*' => ['string', 'max:50', 'distinct'],
            'unit_value' => ['required', 'numeric', 'between:-9999999999.99,9999999999.99'],
            'quantity' => ['required', 'integer'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
        ];
    }

    public function after(): array
    {
        $service = $this->route('service');
        $dayStatus = DayStatus::whereDate('date', $service->service_date_local)->first();

        if ($dayStatus?->status === DayStatusEnum::Executed) {
            $user = $this->user();

            if (! $user->hasAnyRole([Role::ADMIN, Role::SUPER_ADMIN])) {
                return [];
            }

            // Admin on executed day: run contract/driver validation but NOT executed day restriction
            return [
                function ($validator): void {
                    $this->validateContractCoversDate($validator);
                    $this->validateDriverRequired($validator);
                },
            ];
        }

        return parent::after();
    }
}
