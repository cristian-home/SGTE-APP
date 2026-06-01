<?php

namespace Database\Seeders;

use App\Enums\VehicleStatus;
use App\Models\Municipality;
use App\Models\ThirdParty;
use App\Models\Vehicle;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $provider = ThirdParty::where('is_provider', true)->first();

        $bogota = Municipality::where('code', '11001')->first();
        $medellin = Municipality::where('code', '5001')->first();
        $bucaramanga = Municipality::where('code', '68001')->first();
        $cali = Municipality::where('code', '76001')->first();
        $cartagena = Municipality::where('code', '13001')->first();

        $typeId = fn (string $code): ?int => VehicleType::where('code', $code)->value('id');

        $vehicles = [
            [
                'internal_code' => 'V-001',
                'plate' => 'ABC123',
                'mobile_number' => '3101000001',
                'brand' => 'Chevrolet',
                'line' => 'NKR',
                'model_year' => 2022,
                'vehicle_type_id' => $typeId('buseta'),
                'engine_number' => 'CHV2022NKR001',
                'chassis_number' => '9GBNG5CD0N1234567',
                'capacity' => 19,
                'municipality_id' => $bogota?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-03-15',
                'rtm_due_date' => '2027-06-20',
                'operation_card_due_date' => '2028-01-10',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-002',
                'plate' => 'DEF456',
                'mobile_number' => '3101000002',
                'brand' => 'Toyota',
                'line' => 'Coaster',
                'model_year' => 2023,
                'vehicle_type_id' => $typeId('bus'),
                'engine_number' => 'TYT2023CST002',
                'chassis_number' => 'JTGFB518XJ1234568',
                'capacity' => 30,
                'municipality_id' => $bogota?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-05-10',
                'rtm_due_date' => '2027-08-15',
                'operation_card_due_date' => '2028-04-20',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-003',
                'plate' => 'GHI789',
                'mobile_number' => '3101000003',
                'brand' => 'Hyundai',
                'line' => 'County',
                'model_year' => 2021,
                'vehicle_type_id' => $typeId('buseta'),
                'engine_number' => 'HYD2021CNT003',
                'chassis_number' => 'KMJHG51HPJU234569',
                'capacity' => 25,
                'municipality_id' => $medellin?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-01-20',
                'rtm_due_date' => '2027-04-10',
                'operation_card_due_date' => '2027-12-05',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-004',
                'plate' => 'JKL012',
                'mobile_number' => '3101000004',
                'brand' => 'Mercedes-Benz',
                'line' => 'Sprinter',
                'model_year' => 2024,
                'vehicle_type_id' => $typeId('van'),
                'engine_number' => 'MBZ2024SPR004',
                'chassis_number' => 'WDB9066331S234570',
                'capacity' => 15,
                'municipality_id' => $bogota?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-07-25',
                'rtm_due_date' => '2027-10-30',
                'operation_card_due_date' => '2028-06-15',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-005',
                'plate' => 'MNO345',
                'mobile_number' => '3101000005',
                'brand' => 'Kia',
                'line' => 'Pregio',
                'model_year' => 2020,
                'vehicle_type_id' => $typeId('van'),
                'engine_number' => 'KIA2020PRG005',
                'chassis_number' => 'KNCSD81126K234571',
                'capacity' => 12,
                'municipality_id' => $bucaramanga?->id,
                'is_third_party' => true,
                'third_party_id' => $provider?->id,
                'soat_due_date' => '2026-11-30',
                'rtm_due_date' => '2027-02-28',
                'operation_card_due_date' => '2027-09-15',
                'status' => VehicleStatus::Maintenance->value,
            ],
            [
                'internal_code' => 'V-006',
                'plate' => 'PQR678',
                'mobile_number' => '3101000006',
                'brand' => 'Renault',
                'line' => 'Master',
                'model_year' => 2023,
                'vehicle_type_id' => $typeId('van'),
                'engine_number' => 'RNT2023MST006',
                'chassis_number' => 'VF1MA000661234572',
                'capacity' => 16,
                'municipality_id' => $bogota?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-04-12',
                'rtm_due_date' => '2027-09-05',
                'operation_card_due_date' => '2028-03-22',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-007',
                'plate' => 'STU901',
                'mobile_number' => '3101000007',
                'brand' => 'Volkswagen',
                'line' => 'Crafter',
                'model_year' => 2022,
                'vehicle_type_id' => $typeId('van'),
                'engine_number' => 'VWG2022CFT007',
                'chassis_number' => 'WV1ZZZ2EZL1234573',
                'capacity' => 18,
                'municipality_id' => $bogota?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-02-08',
                'rtm_due_date' => '2027-07-18',
                'operation_card_due_date' => '2028-02-01',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-008',
                'plate' => 'VWX234',
                'mobile_number' => '3101000008',
                'brand' => 'Iveco',
                'line' => 'Daily',
                'model_year' => 2024,
                'vehicle_type_id' => $typeId('buseta'),
                'engine_number' => 'IVC2024DLY008',
                'chassis_number' => 'ZCFC5081005234574',
                'capacity' => 22,
                'municipality_id' => $medellin?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2027-08-30',
                'rtm_due_date' => '2027-12-12',
                'operation_card_due_date' => '2028-08-08',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-009',
                'plate' => 'YZA567',
                'mobile_number' => '3101000009',
                'brand' => 'Foton',
                'line' => 'View',
                'model_year' => 2021,
                'vehicle_type_id' => $typeId('buseta'),
                'engine_number' => 'FTN2021VW009',
                'chassis_number' => 'LZWADAGA9MA234575',
                'capacity' => 20,
                'municipality_id' => $cali?->id,
                'is_third_party' => false,
                'third_party_id' => null,
                'soat_due_date' => '2026-12-18',
                'rtm_due_date' => '2027-03-25',
                'operation_card_due_date' => '2027-11-11',
                'status' => VehicleStatus::Active->value,
            ],
            [
                'internal_code' => 'V-010',
                'plate' => 'BCD890',
                'mobile_number' => '3101000010',
                'brand' => 'Nissan',
                'line' => 'Civilian',
                'model_year' => 2019,
                'vehicle_type_id' => $typeId('bus'),
                'engine_number' => 'NSN2019CVL010',
                'chassis_number' => 'JN1CW0EW8K1234576',
                'capacity' => 28,
                'municipality_id' => $cartagena?->id,
                'is_third_party' => true,
                'third_party_id' => $provider?->id,
                'soat_due_date' => '2027-01-25',
                'rtm_due_date' => '2027-05-15',
                'operation_card_due_date' => '2028-01-30',
                'status' => VehicleStatus::Maintenance->value,
            ],
        ];

        foreach ($vehicles as $vehicle) {
            Vehicle::firstOrCreate(
                ['plate' => $vehicle['plate']],
                $vehicle,
            );
        }
    }
}
