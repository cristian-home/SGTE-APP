<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * laravel-activitylog v5 stores the attribute diff in a dedicated
 * `attribute_changes` JSON column (the v4 schema kept it inside `properties`).
 *
 * This migration is intentionally dated immediately after the original
 * activity-log table migrations so it runs before any seeder that logs
 * activity on a fresh database, while still being picked up as a pending
 * migration on existing (production) databases where the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasColumn($tableName, 'attribute_changes')) {
            return;
        }

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->json('attribute_changes')->nullable()->after('properties');
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
        });
    }
};
