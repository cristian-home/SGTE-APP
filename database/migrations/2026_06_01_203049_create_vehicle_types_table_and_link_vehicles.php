<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed values for the new catalog. `cats` are the driver license
     * categories allowed to operate the type (replaces the hardcoded
     * LICENSE_CATEGORY_MAP). Order is the historical enum order.
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

    /**
     * Move vehicle types from the App\Enums\VehicleType enum to a catalog
     * table, non-destructively:
     *   1. create vehicle_types and seed it (known types + any legacy
     *      value actually present in vehicles.type, so backfill always
     *      finds a match in already-populated production databases);
     *   2. add a nullable vehicles.vehicle_type_id and backfill by code;
     *   3. abort if any vehicle is left unmatched (no data is dropped
     *      until every row is linked);
     *   4. enforce NOT NULL (pgsql) and drop the old enum `type` column,
     *      which also removes its CHECK constraint.
     */
    public function up(): void
    {
        // Legacy-conversion migration: only relevant to databases that
        // still have the old enum `type` column. Fresh installs already
        // have vehicle_types (225419) + vehicles.vehicle_type_id
        // (create_vehicles), so there is nothing to convert here.
        if (! Schema::hasColumn('vehicles', 'type')) {
            return;
        }

        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

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

        // Capture any legacy value present in the data that we didn't seed,
        // so the backfill leaves no orphans. Inactive + no license rules:
        // an admin can curate it afterwards in the catalog UI.
        $known = array_column($this->seed, 'code');
        $legacy = DB::table('vehicles')->distinct()->pluck('type')->filter()->reject(
            fn ($code) => in_array($code, $known, true),
        );
        foreach ($legacy as $code) {
            DB::table('vehicle_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => ucfirst((string) $code),
                    'allowed_license_categories' => json_encode([]),
                    'active' => false,
                    'sort_order' => 99,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('vehicle_type_id')
                ->nullable()
                ->after('type')
                ->constrained('vehicle_types')
                ->restrictOnDelete();
        });

        if ($isPgsql) {
            DB::statement('UPDATE vehicles v SET vehicle_type_id = vt.id FROM vehicle_types vt WHERE vt.code = v.type');
        } else {
            foreach (DB::table('vehicle_types')->get(['id', 'code']) as $vt) {
                DB::table('vehicles')->where('type', $vt->code)->update(['vehicle_type_id' => $vt->id]);
            }
        }

        $orphans = DB::table('vehicles')->whereNull('vehicle_type_id')->count();
        if ($orphans > 0) {
            throw new RuntimeException(
                "Backfill incompleto: {$orphans} vehículo(s) sin vehicle_type_id. Migración abortada sin eliminar la columna type.",
            );
        }

        if ($isPgsql) {
            DB::statement('ALTER TABLE vehicles ALTER COLUMN vehicle_type_id SET NOT NULL');
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    /**
     * Restore the enum `type` column, backfill it from the catalog, and
     * drop the FK + catalog table.
     */
    public function down(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->after('model_year');
        });

        if ($isPgsql) {
            DB::statement('UPDATE vehicles v SET type = vt.code FROM vehicle_types vt WHERE vt.id = v.vehicle_type_id');
        } else {
            foreach (DB::table('vehicle_types')->get(['id', 'code']) as $vt) {
                DB::table('vehicles')->where('vehicle_type_id', $vt->id)->update(['type' => $vt->code]);
            }
        }

        if ($isPgsql) {
            DB::statement('ALTER TABLE vehicles ALTER COLUMN type SET NOT NULL');
            DB::statement(<<<'SQL'
                ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check
                CHECK (type::text = ANY (ARRAY['bus', 'buseta', 'microbus', 'van', 'automobile']::text[]))
            SQL);
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_type_id');
        });

        Schema::dropIfExists('vehicle_types');
    }
};
