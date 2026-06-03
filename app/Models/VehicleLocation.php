<?php

namespace App\Models;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One vehicle location reading. See REQ-010.
 *
 * The `Searchable` (Scout) trait was intentionally removed during
 * the `gps-tracking` requirement: this table is a volatile time-
 * series and does not benefit from a Typesense index. `LogsActivity`
 * remains because the audit trail is valuable for tamper-evidence
 * (compliance uses this to verify location claims).
 */
class VehicleLocation extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'vehicle_id',
        'service_id',
        'recorded_at',
        'latitude',
        'longitude',
        'accuracy',
        'is_manual',
        'captured_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'vehicle_id' => 'integer',
            'service_id' => 'integer',
            'recorded_at' => 'datetime',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'accuracy' => 'decimal:2',
            'is_manual' => 'boolean',
            'captured_by' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'id',
                'vehicle_id',
                'service_id',
                'recorded_at',
                'latitude',
                'longitude',
                'accuracy',
                'is_manual',
                'captured_by',
            ]);
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('vehicle.plate'),
            SearchField::substring('vehicle.internal_code'),
        ];
    }
}
