<?php

namespace App\Models;

use App\Concerns\HasTimezone;
use App\Enums\VehicleStatus;
use App\Support\SearchField;
use App\Support\Tz;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Vehicle extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, HasTimezone, LogsActivity, Searchable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'internal_code',
        'plate',
        'mobile_number',
        'brand',
        'line',
        'model_year',
        'vehicle_type_id',
        'engine_number',
        'chassis_number',
        'capacity',
        'municipality_id',
        'is_third_party',
        'third_party_id',
        'timezone',
        'soat_due_at',
        'rtm_due_at',
        'operation_card_due_at',
        // Wall-clock virtuals: setters project Y-m-d into the matching
        // *_due_at instant using the row's TZ. After `timezone` so
        // mass-assign sees the TZ first.
        'soat_due_date',
        'rtm_due_date',
        'operation_card_due_date',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['soat_due_date', 'rtm_due_date', 'operation_card_due_date'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'vehicle_type_id' => 'integer',
            'municipality_id' => 'integer',
            'is_third_party' => 'boolean',
            'third_party_id' => 'integer',
            'soat_due_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'rtm_due_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'operation_card_due_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'timezone' => 'string',
            'status' => VehicleStatus::class,
        ];
    }

    public function getSoatDueDateAttribute(): ?string
    {
        return $this->projectDueAtToYmd('soat_due_at');
    }

    public function setSoatDueDateAttribute(mixed $value): void
    {
        $this->setDueAtFromWallClock('soat_due_at', $value);
    }

    public function getRtmDueDateAttribute(): ?string
    {
        return $this->projectDueAtToYmd('rtm_due_at');
    }

    public function setRtmDueDateAttribute(mixed $value): void
    {
        $this->setDueAtFromWallClock('rtm_due_at', $value);
    }

    public function getOperationCardDueDateAttribute(): ?string
    {
        return $this->projectDueAtToYmd('operation_card_due_at');
    }

    public function setOperationCardDueDateAttribute(mixed $value): void
    {
        $this->setDueAtFromWallClock('operation_card_due_at', $value);
    }

    /**
     * Project a `*_due_at` UTC instant onto the conventional `Y-m-d`
     * representation in the row's TZ. Half-open semantics: due_at is
     * the next-midnight after the conventional last day, so the visible
     * date is `(due_at - 1 second)`.
     */
    protected function projectDueAtToYmd(string $column): ?string
    {
        $value = $this->getAttribute($column);
        if ($value === null) {
            return null;
        }

        return $value->setTimezone($this->resolveTimezone())->subSecond()->format('Y-m-d');
    }

    /**
     * Project a wall-clock `Y-m-d` (or DateTime) onto the matching
     * `*_due_at` instant.
     */
    protected function setDueAtFromWallClock(string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $date = $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $m) ? $m[0] : null);
        if ($date === null) {
            return;
        }
        $this->setAttribute($column, Tz::endOfDayInTzAsUtc($date, $this->resolveTimezone())->utc());
    }

    /**
     * Auto-generate a sequential `V-NNN` code when the caller leaves
     * `internal_code` blank. Looks at the max numeric suffix among
     * existing codes that match `V-<digits>` and picks the next one,
     * zero-padded to three digits (so V-001 sorts before V-010).
     * If the user typed a custom value it is preserved verbatim.
     */
    public static function nextInternalCode(): string
    {
        $maxSuffix = static::query()
            ->withTrashed()
            ->where('internal_code', 'like', 'V-%')
            ->pluck('internal_code')
            ->filter(fn (string $code): bool => (bool) preg_match('/^V-\d+$/', $code))
            ->map(fn (string $code) => (int) substr($code, 2))
            ->max() ?? 0;

        return sprintf('V-%03d', $maxSuffix + 1);
    }

    protected static function booted(): void
    {
        static::creating(function (self $vehicle): void {
            if (blank($vehicle->internal_code)) {
                $vehicle->internal_code = static::nextInternalCode();
            }
        });
    }

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function vehicleLocations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    /**
     * Alias matching the convention used by other rebuilt models
     * (e.g. Service::fuecs()). Prefer this over vehicleLocations().
     */
    public function locations(): HasMany
    {
        return $this->hasMany(VehicleLocation::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'internal_code', 'plate', 'mobile_number', 'brand', 'line', 'model_year', 'vehicle_type_id', 'engine_number', 'chassis_number', 'capacity', 'municipality_id', 'is_third_party', 'third_party_id', 'timezone', 'soat_due_at', 'rtm_due_at', 'operation_card_due_at', 'status']);
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
            'internal_code' => $this->internal_code,
            'plate' => $this->plate,
            'mobile_number' => $this->mobile_number,
            'brand' => $this->brand,
            'line' => $this->line,
            'model_year' => $this->model_year,
            'type' => $this->vehicleType?->code,
            'engine_number' => $this->engine_number,
            'chassis_number' => $this->chassis_number,
            'capacity' => $this->capacity,
            'municipality_id' => $this->municipality_id,
            'is_third_party' => $this->is_third_party,
            'third_party_id' => $this->third_party_id,
            'soat_due_date' => $this->soat_due_date,
            'rtm_due_date' => $this->rtm_due_date,
            'operation_card_due_date' => $this->operation_card_due_date,
            'soat_due_at' => $this->soat_due_at?->toIso8601String(),
            'rtm_due_at' => $this->rtm_due_at?->toIso8601String(),
            'operation_card_due_at' => $this->operation_card_due_at?->toIso8601String(),
            'timezone' => $this->resolveTimezone(),
            'status' => $this->status?->value,
        ];
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('internal_code'),
            SearchField::substring('plate'),
            SearchField::substring('chassis_number'),
            SearchField::substring('engine_number'),
            SearchField::substring('mobile_number'),
            SearchField::fuzzy('brand'),
            SearchField::fuzzy('line'),
        ];
    }
}
