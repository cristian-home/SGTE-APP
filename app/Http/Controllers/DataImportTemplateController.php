<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\VehicleType;
use App\Services\Imports\VehicleImporter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataImportTemplateController extends Controller
{
    public function show(string $type): BinaryFileResponse|Response
    {
        Gate::authorize(Permission::MANAGE_DATA_IMPORTS->value);

        // The vehicles template is generated on the fly so it always
        // carries one example row per active vehicle type — the catalog
        // is dynamic, so a static file would go stale.
        if ($type === 'vehicles') {
            return $this->vehiclesTemplate();
        }

        // URL slug (third-parties) → filename (third_parties.csv).
        $filename = str_replace('-', '_', $type);
        $path = database_path("csv/templates/{$filename}.csv");

        abort_unless(file_exists($path), 404, "Plantilla '{$type}' no encontrada.");

        return response()->download(
            $path,
            "plantilla_{$filename}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function vehiclesTemplate(): Response
    {
        $headers = app(VehicleImporter::class)->expectedHeaders();
        $types = VehicleType::query()->activeOrdered()->get(['code']);

        $lines = [implode(',', $headers)];
        foreach ($types->values() as $i => $type) {
            $n = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $lines[] = implode(',', [
                'ABC'.$n,                 // plate (6 chars)
                'V-'.$n,                  // internal_code
                '3001234567',             // mobile_number
                $type->code,              // type (valid catalog code)
                'Chevrolet',              // brand
                'NPR',                    // line
                '2022',                   // model_year
                'ENG'.$n,                 // engine_number
                'CHS'.$n,                 // chassis_number
                '20',                     // capacity
                '0',                      // is_third_party
                '',                       // third_party_identification
                '2026-12-31',             // soat_due_date
                '2026-12-31',             // rtm_due_date
                '2026-12-31',             // operation_card_due_date
                '11001',                  // municipality_code
                '',                       // timezone
            ]);
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla_vehicles.csv"',
        ]);
    }
}
