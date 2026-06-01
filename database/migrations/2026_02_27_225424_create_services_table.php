<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('driver_id')->nullable()->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained();
            // Denormalized DATE column projected from `planned_start_at AT
            // TIME ZONE timezone`. Recomputed by Service::saving() on every
            // write so day-bucket queries (Gantt, Day Summary, Annual
            // Calendar) stay BTree-indexable without a function call in the
            // WHERE clause.
            $table->date('service_date_local');
            $table->foreignId('origin_municipality_id')->nullable()->constrained('municipalities');
            $table->string('origin_address', 255)->nullable();
            $table->string('origin_coordinates', 50)->nullable();
            // 'google' (pickeada del autocomplete de Google Places) o
            // 'manual' (pin colocado en el mapa por el operador). NULL para
            // coords legacy sin trazabilidad.
            $table->string('origin_coordinates_source', 10)->nullable();
            // Cuando source='google', location_type del Geocoder de Google:
            // ROOFTOP/RANGE_INTERPOLATED/GEOMETRIC_CENTER/APPROXIMATE.
            // NULL para 'manual' o legacy.
            $table->string('origin_coordinates_accuracy', 20)->nullable();
            // Google Place ID de la dirección de origen. Referencia durable
            // del lugar geocodificado; NULL en pines manuales o legacy.
            $table->string('origin_place_id', 255)->nullable();
            $table->foreignId('destination_municipality_id')->nullable()->constrained('municipalities');
            $table->string('destination_address', 255)->nullable();
            $table->string('destination_coordinates', 50)->nullable();
            $table->string('destination_coordinates_source', 10)->nullable();
            $table->string('destination_coordinates_accuracy', 20)->nullable();
            // Google Place ID de la dirección de destino. Referencia durable
            // del lugar geocodificado; NULL en pines manuales o legacy.
            $table->string('destination_place_id', 255)->nullable();
            // UTC instants (TIMESTAMPTZ). Source of truth for ordering and
            // queries. Wall-clock projection is derived from these + the
            // `timezone` column via accessors on the Service model.
            $table->timestampTz('planned_start_at');
            // UTC instant for the planned end. Source of truth for the
            // planned window; the planned duration (minutes) is NOT stored —
            // it is derived on the fly from (planned_end_at - planned_start_at)
            // via the Service::planned_duration accessor.
            $table->timestampTz('planned_end_at')->nullable();
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();
            // IANA timezone the service is operationally scheduled in. Read
            // by accessors and frontend renderers. Defaulted from the
            // contract / config('app.operation_tz') at create time.
            $table->string('timezone', 64)->default('America/Bogota');
            $table->decimal('unit_value', 12, 2);
            $table->integer('quantity')->default(1);
            $table->string('billing_group', 50)->nullable();
            $table->enum('payment_method', ['cash', 'credit', 'transfer'])->default('credit');
            $table->enum('service_status', ['open', 'closed'])->default('open');
            // REQ-009 provenance: captures why a service was created in a
            // Cerrado state for a past date without the driver workflow.
            // Required by ServiceStoreRequest when service_date_local < today
            // (operation TZ) and service_status = closed; null for every
            // other create path.
            $table->string('manual_entry_justification', 500)->nullable();
            // REQ-012 pre-flight decline (driver-preflight-decline-action).
            // Set by DriverDashboardController::decline() when a driver
            // rejects the service before confirmStart. Stays null for every
            // other flow. Ops filters Day Summary on
            // (driver_declined_at IS NOT NULL AND service_status = 'open')
            // to surface services pending reassignment.
            $table->timestampTz('driver_declined_at')->nullable();
            $table->string('driver_decline_reason', 1000)->nullable();
            // Cached driving route between origin and destination,
            // populated by the FetchServiceRoute job whenever both
            // coordinate pairs are set and cleared by the Service
            // model's saving hook when either coord changes.
            // `route_fetched_at` doubles as a "fetch attempted"
            // sentinel — non-null with a null `route_geometry` means
            // the Google Routes API returned no route (or failed) and
            // the map should fall back to a straight line.
            $table->json('route_geometry')->nullable();
            $table->integer('route_distance_m')->nullable();
            $table->integer('route_duration_s')->nullable();
            $table->timestampTz('route_fetched_at')->nullable();
            $table->string('route_source', 32)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('service_date_local');
        });

        Schema::enableForeignKeyConstraints();

        // Trigram GIN index over `billing_group` for ILIKE search in the
        // services index toolbar. Postgres-only; SQLite test driver
        // skips this branch silently.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE INDEX services_billing_group_trgm_idx ON services USING gin (billing_group gin_trgm_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS services_billing_group_trgm_idx');
        }
        Schema::dropIfExists('services');
    }
};
