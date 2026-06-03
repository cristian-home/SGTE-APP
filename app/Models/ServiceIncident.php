<?php

namespace App\Models;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ServiceIncident extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, LogsActivity, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'service_id',
        'incident_type_id',
        'description',
        'registrar_id',
        'is_driver_report',
        'reported_at',
        'affects_billing',
        'additional_value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'service_id' => 'integer',
            'incident_type_id' => 'integer',
            'registrar_id' => 'integer',
            'is_driver_report' => 'boolean',
            'reported_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'affects_billing' => 'boolean',
            'additional_value' => 'decimal:2',
        ];
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'service_id', 'incident_type_id', 'description', 'registrar_id', 'is_driver_report', 'reported_at', 'affects_billing', 'additional_value']);
    }

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return $this->id;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'service_id' => $this->service_id,
            'incident_type_id' => $this->incident_type_id,
            'description' => $this->description,
            'registrar_id' => $this->registrar_id,
            'is_driver_report' => $this->is_driver_report,
            'reported_at' => $this->reported_at?->toIso8601String(),
            'affects_billing' => $this->affects_billing,
            'additional_value' => $this->additional_value !== null ? (float) $this->additional_value : null,
        ];
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::fuzzy('description'),
            SearchField::substring('service.service_number'),
            SearchField::fuzzy('incidentType.name'),
        ];
    }
}
