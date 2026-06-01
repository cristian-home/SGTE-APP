<?php

namespace App\Models;

use App\Concerns\HasTimezone;
use App\Enums\PaymentStatus;
use App\Support\SearchField;
use App\Support\Tz;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, HasTimezone, LogsActivity, Searchable, SoftDeletes;

    /**
     * `issued_at` (TIMESTAMPTZ — start of the issue day in `timezone`)
     * is the source of truth; `issue_date` is a Y-m-d virtual accessor
     * for the UI / activity log payloads.
     */
    protected $fillable = [
        'third_party_id',
        'invoice_number',
        'total_value',
        'timezone',
        'issued_at',
        'issue_date',
        'payment_status',
        'notes',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['issue_date'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'third_party_id' => 'integer',
            'total_value' => 'decimal:2',
            'issued_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'timezone' => 'string',
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function getIssueDateAttribute(): ?string
    {
        return $this->issued_at?->setTimezone($this->resolveTimezone())->format('Y-m-d');
    }

    public function setIssueDateAttribute(mixed $value): void
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
        $this->setAttribute('issued_at', Tz::startOfDayInTzAsUtc($date, $this->resolveTimezone())->utc());
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
            ->logOnly(['id', 'third_party_id', 'invoice_number', 'total_value', 'issued_at', 'timezone', 'payment_status', 'notes']);
    }

    public function getScoutKey(): mixed
    {
        return $this->id;
    }

    /**
     * Compute the next monotonic invoice number for the given calendar
     * year, formatted as `FAC-####-YYYY`. The value is a *preview hint*
     * — the controller's store() loop re-computes inside a transaction
     * and the UNIQUE constraint on the column is the final guarantee
     * that two concurrent stores can't take the same number.
     *
     * Defaults the year to the current year in the operation timezone
     * so fiscal-period rollovers don't depend on the server's UTC date.
     */
    public static function nextInvoiceNumber(?int $year = null): string
    {
        $year ??= (int) \Illuminate\Support\Carbon::now(\App\Support\Tz::operation())->format('Y');
        $sequence = static::query()
            ->where('invoice_number', 'like', "FAC-%-{$year}")
            ->count() + 1;

        return sprintf('FAC-%04d-%d', $sequence, $year);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'third_party_id' => $this->third_party_id,
            'invoice_number' => $this->invoice_number,
            'total_value' => (float) $this->total_value,
            'issue_date' => $this->issue_date,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'timezone' => $this->resolveTimezone(),
            'payment_status' => $this->payment_status?->value,
            'notes' => $this->notes,
        ];
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('invoice_number'),
            SearchField::fuzzy('notes'),
            SearchField::fuzzy(['thirdParty.company_name', 'thirdParty.first_name', 'thirdParty.first_lastname', 'thirdParty.second_lastname']),
        ];
    }
}
