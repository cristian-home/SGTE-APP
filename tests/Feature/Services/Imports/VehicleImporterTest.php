<?php

namespace Tests\Feature\Services\Imports;

use App\Models\DataImport;
use App\Models\DocumentType;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use App\Services\Imports\VehicleImporter;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

function runVehicleImporter(string $csv, ?DataImport $import = null): array
{
    $import ??= DataImport::factory()->create();
    $tmp = tempnam(sys_get_temp_dir(), 'v_').'.csv';
    file_put_contents($tmp, $csv);
    $errorsTmp = tempnam(sys_get_temp_dir(), 'er_').'.csv';
    $writer = SimpleExcelWriter::create($errorsTmp);
    $writer->addHeader(['row_number', 'error_message', 'original_data']);
    $reader = SimpleExcelReader::create($tmp);
    $counters = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errored' => 0];

    app(VehicleImporter::class)->processFile($reader, $import, $writer, function (array $delta) use (&$counters): void {
        foreach ($delta as $key => $value) {
            $counters[$key] += $value;
        }
    });

    $reader->close();
    $writer->close();
    $errors = file_get_contents($errorsTmp);
    @unlink($tmp);
    @unlink($errorsTmp);

    return ['counters' => $counters, 'errors' => $errors];
}

function headerVehicle(): string
{
    return 'plate,internal_code,mobile_number,type,brand,line,model_year,engine_number,chassis_number,capacity,is_third_party,third_party_identification,soat_due_date,rtm_due_date,operation_card_due_date,municipality_code,timezone'."\n";
}

test('valid own-fleet row creates a vehicle and uppercases plate', function (): void {
    $csv = headerVehicle().
        'abc123,V001,3001234567,bus,Chevrolet,NPR,2020,ENG12345,CHS67890,30,0,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    $vehicle = Vehicle::query()->where('plate', 'ABC123')->first();
    expect($vehicle)->not->toBeNull();
    expect($vehicle->is_third_party)->toBeFalse();
});

test('imports a microbus by code and resolves the vehicle type', function (): void {
    $csv = headerVehicle().
        'MIC001,VM01,3001234567,microbus,Hyundai,County,2022,ENGMIC,CHSMIC,20,0,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    expect(Vehicle::query()->where('plate', 'MIC001')->first()->vehicleType->code)->toBe('microbus');
});

test('resolves the vehicle type by display name, accent-insensitive', function (): void {
    $csv = headerVehicle().
        'MIC002,VM02,3001234567,Microbús,Hyundai,County,2022,ENGMI2,CHSMI2,20,0,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    expect(Vehicle::query()->where('plate', 'MIC002')->first()->vehicleType->code)->toBe('microbus');
});

test('third-party vehicle without identification goes to errored', function (): void {
    $csv = headerVehicle().
        'DEF456,V002,3001234567,bus,Chevrolet,NPR,2020,ENG12345,CHS67890,30,1,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['errored'])->toBe(1);
});

test('third-party vehicle with valid identification resolves third_party_id', function (): void {
    DocumentType::firstOrCreate(['code' => 'NIT'], ['name' => 'NIT', 'is_natural_person' => false, 'is_legal_person' => true]);
    $tp = ThirdParty::factory()->create(['identification_number' => '900111222']);

    $csv = headerVehicle().
        'GHI789,V003,3001234567,buseta,Volkswagen,Crafter,2022,ENG54321,CHS09876,18,1,900111222,2026-11-30,2026-09-01,2027-02-15,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    $vehicle = Vehicle::query()->where('plate', 'GHI789')->first();
    expect($vehicle->third_party_id)->toBe($tp->id);
    expect($vehicle->is_third_party)->toBeTrue();
});

test('invalid type goes to errored', function (): void {
    $csv = headerVehicle().
        'JKL012,V004,3001234567,truck,Ford,F150,2021,ENGABC,CHSDEF,5,0,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['errored'])->toBe(1);
});

test('expected headers and natural key', function (): void {
    $importer = app(VehicleImporter::class);
    expect($importer->expectedHeaders())->toContain('plate', 'type', 'soat_due_date', 'timezone');
    expect($importer->naturalKey())->toBe('plate');
});

test('blank timezone column falls back to the column default', function (): void {
    $csv = headerVehicle().
        'MNO345,V005,3009998877,bus,Renault,Master,2023,ENGZZZ,CHSYYY,20,0,,2026-12-31,2026-08-15,2027-03-01,,'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    $vehicle = Vehicle::query()->where('plate', 'MNO345')->first();
    expect($vehicle->timezone)->toBe('America/Bogota');
});

test('explicit timezone column is persisted verbatim', function (): void {
    $csv = headerVehicle().
        'PQR678,V006,3009998877,bus,Hino,300,2024,ENGAAA,CHSBBB,20,0,,2026-12-31,2026-08-15,2027-03-01,,America/New_York'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['created'])->toBe(1);
    $vehicle = Vehicle::query()->where('plate', 'PQR678')->first();
    expect($vehicle->timezone)->toBe('America/New_York');
});

test('invalid timezone goes to errored', function (): void {
    $csv = headerVehicle().
        'STU901,V007,3009998877,bus,Hino,500,2024,ENGCCC,CHSDDD,20,0,,2026-12-31,2026-08-15,2027-03-01,,Atlantis/Standard'."\n";

    $result = runVehicleImporter($csv);

    expect($result['counters']['errored'])->toBe(1);
    expect($result['errors'])->toContain('Zona horaria');
});
