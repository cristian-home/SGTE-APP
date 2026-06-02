<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\VehicleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VehicleUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows(Permission::UPDATE_VEHICLES->value);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // internal_code editable but never required-empty (existing
            // vehicles already have one); blanking it is not a use case.
            'internal_code' => ['required', 'string', 'max:20'],
            'plate' => ['required', 'string', 'max:6'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'brand' => ['nullable', 'string', 'max:50'],
            'line' => ['nullable', 'string', 'max:50'],
            'model_year' => ['nullable', 'integer'],
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'engine_number' => ['nullable', 'string', 'max:50'],
            'chassis_number' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer'],
            'municipality_id' => ['nullable', 'integer', 'exists:municipalities,id'],
            'is_third_party' => ['required', 'boolean'],
            'third_party_id' => [Rule::when($this->boolean('is_third_party'), ['required', 'integer', 'exists:third_parties,id'], ['nullable', 'integer', 'exists:third_parties,id'])],
            'soat_due_date' => ['required', 'date'],
            'rtm_due_date' => ['required', 'date'],
            'operation_card_due_date' => ['required', 'date'],
            'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
            'status' => ['required', Rule::enum(VehicleStatus::class)],
        ];
    }
}
