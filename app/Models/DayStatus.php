<?php

namespace App\Models;

use App\Enums\DayStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DayStatus extends Model
{
    use HasFactory, LogsActivity, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date',
        'status',
        'executor_id',
        'executed_at',
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
            'date' => 'immutable_date:Y-m-d',
            'status' => DayStatusEnum::class,
            'executor_id' => 'integer',
            'executed_at' => 'immutable_datetime:Y-m-d H:i:sP',
        ];
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'date', 'status', 'executor_id', 'executed_at']);
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
            'date' => $this->date?->toDateString(),
            'status' => $this->status?->value,
            'executor_id' => $this->executor_id,
            'executed_at' => $this->executed_at?->toIso8601String(),
        ];
    }
}
