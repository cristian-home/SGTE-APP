<?php

namespace App\Models;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DocumentType extends Model
{
    use Concerns\SearchesDatabase;
    use HasFactory, SoftDeletes;
    use LogsActivity, Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'code',
        'name',
        'is_natural_person',
        'is_legal_person',
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
            'is_natural_person' => 'boolean',
            'is_legal_person' => 'boolean',
        ];
    }

    public function thirdParties(): HasMany
    {
        return $this->hasMany(ThirdParty::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'code', 'name', 'is_natural_person', 'is_legal_person']);
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
            'code' => $this->code,
            'name' => $this->name,
            'is_natural_person' => $this->is_natural_person,
            'is_legal_person' => $this->is_legal_person,
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
