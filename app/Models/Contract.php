<?php

namespace App\Models;

use App\Concerns\HasTimezone;
use App\Enums\BillingUnitType;
use App\Enums\ContractObject;
use App\Support\SearchField;
use App\Support\Tz;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Contract extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, HasTimezone, LogsActivity, Searchable, SoftDeletes;

    /**
     * `start_at` / `end_at` are the source of truth — UTC instants under
     * half-open semantics (`end_at` is the next-midnight after the
     * conventional last day). `timezone` carries the IANA zone the
     * accessors / setters use to project wall-clock dates.
     *
     * `start_date` / `end_date` are virtual accessors emitting `Y-m-d`
     * (in `$appends`); they exist for backwards compatibility with
     * existing UI / activity-log payloads.
     */
    protected $fillable = [
        'contract_number',
        'third_party_id',
        'contract_object',
        'timezone',
        'start_at',
        'end_at',
        // `start_date` / `end_date` are virtual: setters project the
        // wall-clock day onto `start_at` / `end_at` using the row's TZ.
        // Listed in $fillable so factories / seeders can mass-assign
        // them; the timezone attribute MUST be set first when used
        // together (handled by the order in this list).
        'start_date',
        'end_date',
        'route_description',
        'is_generic',
        'active',
        'billing_unit_type',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'third_party_id' => 'integer',
            'contract_object' => ContractObject::class,
            'start_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'end_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'timezone' => 'string',
            'is_generic' => 'boolean',
            'active' => 'boolean',
            'billing_unit_type' => BillingUnitType::class,
        ];
    }

    /**
     * Wall-clock first day of the contract, projected to `Y-m-d` in the
     * row's timezone.
     */
    public function getStartDateAttribute(): ?string
    {
        return $this->start_at?->setTimezone($this->resolveTimezone())->format('Y-m-d');
    }

    /**
     * Wall-clock last day of the contract — i.e. one second before
     * `end_at` (because `end_at` is exclusive next-midnight).
     */
    public function getEndDateAttribute(): ?string
    {
        if ($this->end_at === null) {
            return null;
        }

        return $this->end_at->setTimezone($this->resolveTimezone())->subSecond()->format('Y-m-d');
    }

    /**
     * Wall-clock setter for the contract's first day. Projects the
     * picked `Y-m-d` onto `start_at` (00:00 of that day in the row's
     * TZ). Tolerates a CarbonImmutable / DateTime input from factories
     * that build `start_date` from `fake()->dateTimeBetween()`.
     */
    public function setStartDateAttribute(mixed $value): void
    {
        $date = $this->normalizeDateInput($value);
        if ($date === null) {
            return;
        }
        $this->setAttribute('start_at', Tz::startOfDayInTzAsUtc($date, $this->resolveTimezone())->utc());
    }

    /**
     * Wall-clock setter for the contract's last day. Projects onto
     * `end_at` = next-midnight after the picked day (half-open).
     */
    public function setEndDateAttribute(mixed $value): void
    {
        $date = $this->normalizeDateInput($value);
        if ($date === null) {
            return;
        }
        $this->setAttribute('end_at', Tz::endOfDayInTzAsUtc($date, $this->resolveTimezone())->utc());
    }

    /**
     * Coerce DateTime / CarbonImmutable / string into a `Y-m-d` string
     * for projection. Returns null when the value can't be parsed.
     */
    protected function normalizeDateInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Filter to contracts active at a given UTC instant. Half-open
     * comparison: `start_at <= instant < end_at`.
     */
    public function scopeActiveAt(Builder $query, CarbonImmutable $instant): Builder
    {
        return $query
            ->where('start_at', '<=', $instant->utc())
            ->where('end_at', '>', $instant->utc());
    }

    /**
     * Filter to contracts active on a given calendar day in the
     * supplied TZ (defaults to operation TZ). The instant compared
     * against is the start of that day in the TZ.
     */
    public function scopeActiveOnDay(Builder $query, string $ymd, ?string $tz = null): Builder
    {
        $tz ??= Tz::operation();
        $instant = Tz::startOfDayInTzAsUtc($ymd, $tz);

        return $query
            ->where('start_at', '<=', $instant)
            ->where('end_at', '>', $instant);
    }

    public function thirdParty(): BelongsTo
    {
        return $this->belongsTo(ThirdParty::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'id',
                'contract_number',
                'third_party_id',
                'contract_object',
                'start_at',
                'end_at',
                'timezone',
                'route_description',
                'is_generic',
                'active',
                'billing_unit_type',
            ]);
    }

    public function getScoutKey(): mixed
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'contract_number' => $this->contract_number,
            'third_party_id' => $this->third_party_id,
            'contract_object' => $this->contract_object,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'timezone' => $this->resolveTimezone(),
            'route_description' => $this->route_description,
            'is_generic' => $this->is_generic,
            'active' => $this->active,
        ];
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('contract_number'),
            SearchField::fuzzy('route_description'),
            SearchField::fuzzy(['thirdParty.company_name', 'thirdParty.first_name', 'thirdParty.first_lastname', 'thirdParty.second_lastname']),
        ];
    }
}
