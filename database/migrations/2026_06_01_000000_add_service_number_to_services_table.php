<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the user-facing service consecutive (`SRV-NNNN-YYYY`) plus the
     * durable per-year counter that backs reservation. Existing services are
     * backfilled in id order, grouped by their creation year in the operation
     * timezone, and the counter is seeded to the max assigned per year so the
     * next reservation continues without collision.
     */
    public function up(): void
    {
        Schema::create('service_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('year')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestampsTz();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('service_number', 50)->nullable()->after('id');
        });

        $tz = (string) config('app.operation_tz', 'America/Bogota');

        /** @var array<int, int> $counters year => last assigned number */
        $counters = [];

        DB::table('services')
            ->orderBy('id')
            ->select('id', 'created_at')
            ->get()
            ->each(function (object $row) use (&$counters, $tz): void {
                $created = $row->created_at !== null
                    ? Carbon::parse($row->created_at)->setTimezone($tz)
                    : Carbon::now($tz);
                $year = (int) $created->format('Y');
                $counters[$year] = ($counters[$year] ?? 0) + 1;

                DB::table('services')
                    ->where('id', $row->id)
                    ->update(['service_number' => sprintf('SRV-%04d-%d', $counters[$year], $year)]);
            });

        $now = Carbon::now();
        foreach ($counters as $year => $last) {
            DB::table('service_number_sequences')->insert([
                'year' => $year,
                'last_number' => $last,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('services', function (Blueprint $table) {
            $table->string('service_number', 50)->nullable(false)->change();
            $table->unique('service_number');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['service_number']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('service_number');
        });

        Schema::dropIfExists('service_number_sequences');
    }
};
