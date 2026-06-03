<?php

namespace App\Models;

use App\Concerns\HasTimezone;
use App\Enums\DataImportStatus;
use App\Enums\DataImportType;
use Database\Factories\DataImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DataImport extends Model
{
    /** @use HasFactory<DataImportFactory> */
    use HasFactory, HasTimezone, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'type',
        'original_filename',
        'disk',
        'path',
        'errors_path',
        'status',
        'dry_run',
        'update_existing',
        'rows_total',
        'rows_processed',
        'rows_created',
        'rows_updated',
        'rows_skipped',
        'rows_errored',
        'error_message',
        'started_at',
        'completed_at',
        'files_purged_at',
        'timezone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'type' => DataImportType::class,
            'status' => DataImportStatus::class,
            'dry_run' => 'boolean',
            'update_existing' => 'boolean',
            'rows_total' => 'integer',
            'rows_processed' => 'integer',
            'rows_created' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'rows_errored' => 'integer',
            'started_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'completed_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'files_purged_at' => 'immutable_datetime:Y-m-d H:i:sP',
            'timezone' => 'string',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'original_filename', 'status', 'dry_run', 'update_existing'])
            ->logOnlyDirty();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [DataImportStatus::Completed, DataImportStatus::Failed], true);
    }

    public function hasFiles(): bool
    {
        return $this->files_purged_at === null && $this->path !== null;
    }
}
