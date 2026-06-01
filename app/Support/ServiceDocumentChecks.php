<?php

namespace App\Support;

use App\Enums\LicenseCategory;
use App\Enums\VehicleType;
use App\Models\Contract;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Stateless helpers that run the REQ-003/004/005 document + coverage
 * checks against a given Contract / Vehicle / Driver triple. These
 * mirror the protected methods on `App\Http\Requests\ServiceStoreRequest`
 * and are designed to be reused by other flows that need the same
 * domain rule — in particular `FuecPreGenerationChecks` for REQ-007.
 *
 * Every method returns either `null` (check passed) or a Spanish
 * error message. Multi-error checks (vehicle documents) return an
 * array of messages. Callers decide how to surface them to the user.
 */
class ServiceDocumentChecks
{
    /**
     * REQ-005 driver license category vs vehicle type compatibility.
     * Best-guess mapping per Colombian licensing rules (see
     * project_license_category_map memory). Update when client rules.
     *
     * @var array<string, list<string>>
     */
    public const LICENSE_CATEGORY_MAP = [
        VehicleType::Bus->value => [LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Buseta->value => [LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Microbus->value => [LicenseCategory::C1->value, LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Van->value => [LicenseCategory::C1->value, LicenseCategory::C2->value, LicenseCategory::C3->value],
        VehicleType::Automobile->value => [LicenseCategory::C1->value, LicenseCategory::C2->value, LicenseCategory::C3->value],
    ];

    public static function contractCoversDate(Contract $contract, Carbon $date): ?string
    {
        if (! $contract->active) {
            return 'El contrato asociado no está vigente.';
        }

        if ($contract->start_at === null || $contract->end_at === null) {
            return 'El contrato no tiene fechas de vigencia configuradas.';
        }

        // Half-open interval [start_at, end_at). The contract covers
        // `$date` when the service's instant lies inside it.
        $instant = $date->copy()->utc();
        if ($instant->lt($contract->start_at) || $instant->gte($contract->end_at)) {
            return 'La fecha del servicio no está dentro del rango del contrato.';
        }

        return null;
    }

    /**
     * REQ-004 AC 3-5. Returns a list of Spanish error messages (one
     * per expired/missing document). Empty list means all documents
     * are valid at the given service instant.
     *
     * @return list<string>
     */
    public static function vehicleDocumentsValid(Vehicle $vehicle, Carbon $date): array
    {
        $instant = $date->copy()->utc();
        $documents = [
            'soat_due_at' => 'SOAT',
            'rtm_due_at' => 'RTM',
            'operation_card_due_at' => 'Tarjeta de Operación',
        ];

        $errors = [];

        foreach ($documents as $column => $label) {
            $dueAt = $vehicle->{$column};

            if ($dueAt === null) {
                $errors[] = "El vehículo no tiene registrado el {$label}.";

                continue;
            }

            if ($instant->greaterThanOrEqualTo($dueAt)) {
                $visible = $vehicle->{str_replace('_at', '_date', $column)};
                $errors[] = "El {$label} del vehículo está vencido (venció {$visible}).";
            }
        }

        return $errors;
    }

    /**
     * REQ-003 AC 5 / REQ-005 AC 2. Returns a list of Spanish error
     * messages covering license expiry, missing license category,
     * incompatible category for the vehicle type, and inactive
     * social security.
     *
     * @return list<string>
     */
    public static function driverLicenseValid(Driver $driver, Vehicle $vehicle, Carbon $date): array
    {
        $errors = [];
        $instant = $date->copy()->utc();

        if ($driver->license_due_at === null) {
            $errors[] = 'El conductor no tiene registrada la fecha de vencimiento de la licencia.';
        } elseif ($instant->greaterThanOrEqualTo($driver->license_due_at)) {
            $errors[] = "La licencia del conductor está vencida (venció {$driver->license_due_date}).";
        }

        if ($driver->has_social_security === false) {
            $errors[] = 'El conductor no tiene seguridad social activa.';
        }

        if ($vehicle->type !== null && $driver->license_category !== null) {
            $vehicleType = $vehicle->type instanceof VehicleType ? $vehicle->type->value : (string) $vehicle->type;
            $allowed = self::LICENSE_CATEGORY_MAP[$vehicleType] ?? [];
            $driverCategory = $driver->license_category instanceof LicenseCategory
                ? $driver->license_category->value
                : (string) $driver->license_category;

            if ($allowed !== [] && ! in_array($driverCategory, $allowed, true)) {
                $errors[] = "La categoría de licencia {$driverCategory} del conductor no es compatible con el tipo de vehículo.";
            }
        }

        return $errors;
    }
}
