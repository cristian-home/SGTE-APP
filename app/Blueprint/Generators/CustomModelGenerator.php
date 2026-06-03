<?php

namespace App\Blueprint\Generators;

use Blueprint\Generators\ModelGenerator;
use Blueprint\Models\Model;

class CustomModelGenerator extends ModelGenerator
{
    /**
     * @var array<int, string>
     */
    protected array $activityLogExcludedColumns = ['password', 'remember_token', 'deleted_at', 'created_at', 'updated_at'];

    /**
     * @var array<int, string>
     */
    protected array $searchableExcludedColumns = ['id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'deleted_at', 'created_at', 'updated_at'];

    protected function populateStub(string $stub, Model $model): string
    {
        // First, let the parent generate the base class
        $stub = parent::populateStub($stub, $model);

        // If it's a pivot we might not want these traits, but usually Blueprint focuses on regular models
        if ($model->isPivot()) {
            return $stub;
        }

        // Add imports for Spatie Activity Log
        $stub = $this->addActivityLogTraits($stub, $model);

        // Add imports for Laravel Scout
        $stub = $this->addScoutTraits($stub, $model);

        return $stub;
    }

    protected function addActivityLogTraits(string $stub, Model $model): string
    {
        $stub = $this->insertUses($stub, [
            'Spatie\Activitylog\Models\Concerns\LogsActivity',
            'Spatie\Activitylog\Support\LogOptions',
        ]);

        // Add trait to class
        if (str_contains($stub, 'use HasFactory;')) {
            $stub = str_replace('use HasFactory;', 'use HasFactory, LogsActivity;', $stub);
        } else {
            $stub = preg_replace('/class [a-zA-Z0-9_]+ extends Model\n\{/', "$0\n    use LogsActivity;\n", $stub);
        }

        $columns = array_keys($model->columns());
        $logFields = [];
        foreach ($columns as $col) {
            if (! in_array($col, $this->activityLogExcludedColumns, true)) {
                $logFields[] = "'$col'";
            }
        }
        $logFieldsStr = implode(', ', $logFields);

        // Add getActivitylogOptions method
        $method = <<<PHP

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->logOnly([{$logFieldsStr}]);
    }
PHP;
        $stub = $this->appendMethodBeforeClassEnd($stub, $method);

        return $stub;
    }

    protected function addScoutTraits(string $stub, Model $model): string
    {
        $stub = $this->insertUses($stub, ['Laravel\Scout\Searchable']);

        // Add trait to class
        if (str_contains($stub, 'use LogsActivity;')) {
            $stub = str_replace('use LogsActivity;', 'use LogsActivity, Searchable;', $stub);
        } elseif (str_contains($stub, 'use HasFactory,')) {
            $stub = str_replace('use HasFactory,', 'use HasFactory, Searchable,', $stub);
        } else {
            $stub = preg_replace('/class [a-zA-Z0-9_]+ extends Model\n\{/', "$0\n    use Searchable;\n", $stub);
        }

        $columns = array_keys($model->columns());
        $arrayPairs = [];
        $arrayPairs[] = "            'id' => (string) \$this->id,";
        foreach ($columns as $column) {
            if (in_array($column, $this->searchableExcludedColumns, true)) {
                continue;
            }
            $arrayPairs[] = "            '{$column}' => \$this->{$column},";
        }
        $arrayContent = implode("\n", $arrayPairs);

        // Add getScoutKey and toSearchableArray methods
        $method = <<<PHP

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return \$this->id;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
{$arrayContent}
        ];
    }
PHP;

        $stub = $this->appendMethodBeforeClassEnd($stub, $method);

        return $stub;
    }

    /**
     * @param  array<int, string>  $imports
     */
    protected function insertUses(string $stub, array $imports): string
    {
        $imports = array_values(array_unique($imports));
        $missingImports = array_filter($imports, fn (string $import): bool => ! str_contains($stub, "use {$import};"));

        if ($missingImports === []) {
            return $stub;
        }

        $importBlock = implode("\n", array_map(fn (string $import): string => "use {$import};", $missingImports));

        if (str_contains($stub, 'use Illuminate\Database\Eloquent\Factories\HasFactory;')) {
            return str_replace(
                'use Illuminate\Database\Eloquent\Factories\HasFactory;',
                "use Illuminate\Database\Eloquent\Factories\HasFactory;\n{$importBlock}",
                $stub,
            );
        }

        return str_replace(
            'use Illuminate\Database\Eloquent\Model;',
            "use Illuminate\Database\Eloquent\Model;\n{$importBlock}",
            $stub,
        );
    }

    protected function appendMethodBeforeClassEnd(string $stub, string $method): string
    {
        if (str_contains($stub, trim($method))) {
            return $stub;
        }

        return preg_replace('/\}$/', $method."\n}", $stub) ?? $stub;
    }
}
