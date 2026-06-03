<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add 'microbus' to the vehicles.type CHECK constraint.
     *
     * The App\Enums\VehicleType enum gained the Microbus case, but the
     * column's CHECK constraint (created by the original enum() column)
     * still only allowed the original four values, so inserting a
     * microbús failed at the DB level on PostgreSQL with a 23514 check
     * violation — both via import and via the create form.
     *
     * Fresh databases get the corrected list straight from the (edited)
     * create_vehicles_table migration; this migration only needs to
     * patch already-migrated PostgreSQL databases. SQLite enforces the
     * enum check inline in the table definition and cannot drop a named
     * constraint, but its test databases always migrate fresh, so it is
     * already covered and skipped here.
     */
    public function up(): void
    {
        // Fresh installs create vehicles with a vehicle_type_id FK and no
        // legacy `type` column, so there is no CHECK constraint to patch.
        if (! Schema::hasColumn('vehicles', 'type')) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT vehicles_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check
            CHECK (type::text = ANY (ARRAY['bus', 'buseta', 'microbus', 'van', 'automobile']::text[]))
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'type')) {
            return;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT vehicles_type_check');
        DB::statement(<<<'SQL'
            ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check
            CHECK (type::text = ANY (ARRAY['bus', 'buseta', 'van', 'automobile']::text[]))
        SQL);
    }
};
