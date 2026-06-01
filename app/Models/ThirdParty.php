<?php

namespace App\Models;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ThirdParty extends Model
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
        'document_type_id',
        'identification_number',
        'is_natural_person',
        'first_name',
        'second_name',
        'first_lastname',
        'second_lastname',
        'company_name',
        'trade_name',
        'municipality_id',
        'address',
        'phone',
        'email',
        'is_customer',
        'is_provider',
        'active',
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
            'document_type_id' => 'integer',
            'municipality_id' => 'integer',
            'is_natural_person' => 'boolean',
            'is_customer' => 'boolean',
            'is_provider' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id', 'document_type_id', 'identification_number', 'is_natural_person', 'first_name', 'second_name', 'first_lastname', 'second_lastname', 'company_name', 'trade_name', 'municipality_id', 'address', 'phone', 'email', 'is_customer', 'is_provider', 'active']);
    }

    /**
     * @return array<int, SearchField>
     */
    public function searchableColumns(): array
    {
        return [
            SearchField::substring('identification_number'),
            SearchField::fuzzy('company_name'),
            SearchField::fuzzy('trade_name'),
            SearchField::fuzzy(['first_name', 'second_name', 'first_lastname', 'second_lastname']),
        ];
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
            'document_type_id' => $this->document_type_id,
            'identification_number' => $this->identification_number,
            'is_natural_person' => $this->is_natural_person,
            'first_name' => $this->first_name,
            'second_name' => $this->second_name,
            'first_lastname' => $this->first_lastname,
            'second_lastname' => $this->second_lastname,
            'company_name' => $this->company_name,
            'trade_name' => $this->trade_name,
            'municipality_id' => $this->municipality_id,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_customer' => $this->is_customer,
            'is_provider' => $this->is_provider,
            'active' => $this->active,
        ];
    }
}
