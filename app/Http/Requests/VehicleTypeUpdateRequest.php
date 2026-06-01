<?php

namespace App\Http\Requests;

use App\Enums\LicenseCategory;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VehicleTypeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows(Permission::UPDATE_VEHICLE_TYPES->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('vehicle_types', 'code')->ignore($this->vehicle_type)],
            'name' => ['required', 'string', 'max:60'],
            'allowed_license_categories' => ['nullable', 'array'],
            'allowed_license_categories.*' => [Rule::enum(LicenseCategory::class)],
            'active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
