<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A MinTransporte-authorized FUEC consecutive range. See REQ-007
 * AC#3: every FUEC must carry a consecutive number from an externally-
 * granted range (resolution document issued by the Ministerio de
 * Transporte). Only one range may be `active` at a time; when the
 * active range is exhausted the admin registers a new one via the
 * Fuec Number Range CRUD.
 */
class FuecNumberRange extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'resolution_number',
        'resolution_year',
        'range_from',
        'range_to',
        'active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'resolution_year' => 'integer',
            'range_from' => 'integer',
            'range_to' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function fuecs(): HasMany
    {
        return $this->hasMany(Fuec::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['resolution_number', 'resolution_year', 'range_from', 'range_to', 'active', 'notes']);
    }

    /**
     * Count of consecutive numbers still available in this range.
     * When the range has issued no FUECs yet, returns the full span.
     */
    public function remaining(): int
    {
        $highest = $this->fuecs()->max('consecutive_number');
        if ($highest === null) {
            return (int) ($this->range_to - $this->range_from + 1);
        }

        return max(0, (int) ($this->range_to - (int) $highest));
    }
}
