<?php

namespace App\Rules;

use App\Enums\ServiceStatus;
use App\Models\FuecNumberRange;
use App\Models\Service;
use App\Support\ServiceDocumentChecks;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * REQ-007 pre-generation validation gauntlet. Run from the
 * `FuecStoreRequest::after()` hook to surface every failing check
 * as a top-level `fuec_pre_generation.*` validator error so the UI
 * can render them as a Spanish-language list before the admin ever
 * reaches the FuecGenerator service. Also re-run inside the
 * generator's transaction as a defense-in-depth against races.
 */
class FuecPreGenerationChecks
{
    public function __construct(
        protected ?Service $service,
    ) {}

    public function __invoke(Validator $validator): void
    {
        $this->run($validator);
    }

    public function run(Validator $validator): void
    {
        $service = $this->service;

        if (! $service) {
            $validator->errors()->add('fuec_pre_generation.service', 'El servicio no existe.');

            return;
        }

        if ($service->service_status !== ServiceStatus::Closed) {
            $validator->errors()->add('fuec_pre_generation.service', 'El servicio no está cerrado.');

            return;
        }

        $contract = $service->contract;
        $vehicle = $service->vehicle;
        $driver = $service->driver;

        if (! $contract) {
            $validator->errors()->add('fuec_pre_generation.contract', 'El servicio no tiene contrato asociado.');

            return;
        }

        if (! $vehicle) {
            $validator->errors()->add('fuec_pre_generation.vehicle', 'El servicio no tiene vehículo asociado.');

            return;
        }

        if (! $driver) {
            $validator->errors()->add('fuec_pre_generation.driver', 'El servicio no tiene conductor asociado.');

            return;
        }

        // The FUEC is a legal transport authorization: it must state the
        // origin and destination cities. Block generation when either is
        // missing (covers legacy services created before cities were
        // required).
        if (! $service->origin_municipality_id) {
            $validator->errors()->add('fuec_pre_generation.location', 'El servicio no tiene ciudad de origen; el FUEC la requiere.');
        }

        if (! $service->destination_municipality_id) {
            $validator->errors()->add('fuec_pre_generation.location', 'El servicio no tiene ciudad de destino; el FUEC la requiere.');
        }

        $today = Carbon::now((string) config('app.operation_tz'));

        if ($error = ServiceDocumentChecks::contractCoversDate($contract, $today)) {
            $validator->errors()->add('fuec_pre_generation.contract', $error);
        }

        foreach (ServiceDocumentChecks::vehicleDocumentsValid($vehicle, $today) as $msg) {
            $validator->errors()->add('fuec_pre_generation.vehicle', $msg);
        }

        foreach (ServiceDocumentChecks::driverLicenseValid($driver, $vehicle, $today) as $msg) {
            $validator->errors()->add('fuec_pre_generation.driver', $msg);
        }

        $this->checkRangeAvailable($validator);
        // Auto-supersede policy (Q7): a service may accumulate FUECs over
        // time but only one is active at any moment. Generating a new one
        // automatically cancels the previous active row inside FuecGenerator's
        // transaction; no pre-check rejection here. See bug-log:BUG-06.
    }

    protected function checkRangeAvailable(Validator $validator): void
    {
        $range = FuecNumberRange::query()->where('active', true)->first();

        if (! $range) {
            $validator->errors()->add(
                'fuec_pre_generation.range',
                'No hay un rango MinTransporte activo. Registre uno en Administración → Rangos FUEC.',
            );

            return;
        }

        if ($range->remaining() <= 0) {
            $validator->errors()->add(
                'fuec_pre_generation.range',
                'El rango MinTransporte activo se agotó. Registre un nuevo rango.',
            );
        }
    }
}
