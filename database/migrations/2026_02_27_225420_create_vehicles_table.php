<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('internal_code', 20);
            $table->string('plate', 6);
            $table->string('mobile_number', 20);
            $table->string('brand', 50);
            $table->string('line', 50);
            $table->integer('model_year');
            $table->enum('type', ['bus', 'buseta', 'microbus', 'van', 'automobile']);
            $table->string('engine_number', 50);
            $table->string('chassis_number', 50);
            $table->integer('capacity');
            $table->foreignId('municipality_id')->nullable()->constrained();
            $table->boolean('is_third_party')->default(false);
            $table->foreignId('third_party_id')->nullable()->constrained();
            // Half-open exclusive instants for legal document validity.
            // UTC TIMESTAMPTZ projected from the wall-clock day in
            // `timezone` (next-midnight semantics). See
            // App\Concerns\HasTimezone + Vehicle model accessors.
            $table->timestampTz('soat_due_at');
            $table->timestampTz('rtm_due_at');
            $table->timestampTz('operation_card_due_at');
            $table->string('timezone', 64)->default('America/Bogota');
            $table->enum('status', ['active', 'maintenance', 'retired'])->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
