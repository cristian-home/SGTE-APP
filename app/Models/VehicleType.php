<?php

namespace App\Models;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catalog of vehicle types (formerly the App\Enums\VehicleType enum).
 * Moving this to a table lets the client add new types as data — no code
 * change, no migration. `code` is the stable machine identifier used by
 * the CSV importer and FK backfills; `name` is the display label;
 * `allowed_license_categories` holds the driver license categories that
 * may operate the type (replaces the old hardcoded LICENSE_CATEGORY_MAP).
 */
class VehicleType extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, SoftDeletes;
    use LogsActivity, Searchable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'allowed_license_categories',
        'active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'allowed_license_categories' => 'array',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Active types in their configured display order — the canonical
     * option list for selects, imports and the template.
     *
     * @param  Builder<VehicleType>  $query
     * @return Builder<VehicleType>
     */
    public function scopeActiveOrdered(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'code', 'name', 'allowed_license_categories', 'active', 'sort_order']);
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
            'code' => $this->code,
            'name' => $this->name,
        ];
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('code'),
            SearchField::fuzzy('name'),
        ];
    }
}
