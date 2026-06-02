<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle types catalog. Created here (before create_vehicles + the
     * seeders) so a FRESH `migrate:fresh --seed` has the table ready when
     * VehicleSeeder runs. On already-migrated databases the table was
     * created by 2026_06_01_..._create_vehicle_types_table_and_link_vehicles,
     * so this is a guarded no-op there. [[project_vehicle_type_catalog]]
     *
     * @var list<array{code: string, name: string, cats: list<string>, sort: int}>
     */
    private array $seed = [
        ['code' => 'bus', 'name' => 'Bus', 'cats' => ['C2', 'C3'], 'sort' => 1],
        ['code' => 'buseta', 'name' => 'Buseta', 'cats' => ['C2', 'C3'], 'sort' => 2],
        ['code' => 'microbus', 'name' => 'Microbús', 'cats' => ['C1', 'C2', 'C3'], 'sort' => 3],
        ['code' => 'van', 'name' => 'Van', 'cats' => ['C1', 'C2', 'C3'], 'sort' => 4],
        ['code' => 'automobile', 'name' => 'Automóvil', 'cats' => ['C1', 'C2', 'C3'], 'sort' => 5],
    ];

    public function up(): void
    {
        if (Schema::hasTable('vehicle_types')) {
            return;
        }

        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->json('allowed_license_categories')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        $now = now();
        foreach ($this->seed as $row) {
            DB::table('vehicle_types')->updateOrInsert(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'allowed_license_categories' => json_encode($row['cats']),
                    'active' => true,
                    'sort_order' => $row['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
