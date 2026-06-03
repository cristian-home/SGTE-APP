<?php

namespace App\Models;

use App\Enums\FuecStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A generated FUEC document (REQ-007). Persistent + immutable
 * post-creation — the only state transition is `active → cancelled`
 * via the `cancel` controller action (writes an activity log entry
 * with the cancellation reason). FUECs are never edited or hard-
 * deleted; regeneration is handled by cancelling + creating a new
 * FUEC with the next consecutive.
 */
class Fuec extends Model
{
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use Searchable;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'service_id',
        'fuec_number_range_id',
        'consecutive_number',
        'generated_at',
        'qr_code',
        'status',
        'pdf_path',
        'pdf_disk',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'service_id' => 'integer',
            'fuec_number_range_id' => 'integer',
            'consecutive_number' => 'integer',
            'generated_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'status' => FuecStatus::class,
        ];
    }

    /**
     * Only the `uuid` column is a generated UUID; `id` remains a
     * bigint auto-increment so every other FK relation keeps working.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function fuecNumberRange(): BelongsTo
    {
        return $this->belongsTo(FuecNumberRange::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'id',
                'uuid',
                'service_id',
                'fuec_number_range_id',
                'consecutive_number',
                'generated_at',
                'qr_code',
                'status',
                'pdf_path',
                'pdf_disk',
                'cancellation_reason',
            ]);
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
            'uuid' => $this->uuid,
            'service_id' => $this->service_id,
            'fuec_number_range_id' => $this->fuec_number_range_id,
            'consecutive_number' => (string) $this->consecutive_number,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'qr_code' => $this->qr_code,
            'status' => $this->status?->value,
            'pdf_path' => $this->pdf_path,
            'pdf_disk' => $this->pdf_disk,
        ];
    }
}
