<?php

namespace App\Blueprint\Generators;

use App\Enums\Permission;
use Blueprint\Generators\ControllerGenerator;
use Blueprint\Models\Controller;
use Blueprint\Models\Statements\QueryStatement;
use Blueprint\Models\Statements\SendStatement;
use Illuminate\Support\Str;

class CustomControllerGenerator extends ControllerGenerator
{
    /**
     * Models whose permission prefix doesn't follow the convention
     * Str::upper(Str::snake(Str::plural($modelName))).
     */
    private const PERMISSION_PREFIX_OVERRIDES = [
        'ServiceIncident' => 'INCIDENTS',
        'DayStatus' => 'DAY_SUMMARY',
        'Fuec' => 'FUEC',
    ];

    /**
     * Map controller method names to CRUD permission action prefixes.
     */
    private const METHOD_TO_ACTION = [
        'index' => 'VIEW',
        'show' => 'VIEW',
        'create' => 'CREATE',
        'store' => 'CREATE',
        'edit' => 'UPDATE',
        'update' => 'UPDATE',
        'destroy' => 'DELETE',
    ];

    protected function populateStub(string $stub, Controller $controller): string
    {
        $this->ensureNotificationImports($controller);

        if ($this->hasIndexCollectionQuery($controller)) {
            $this->addImport($controller, 'Spatie\QueryBuilder\QueryBuilder');
        }

        $permissionMap = $this->buildPermissionMap($controller);

        if (! empty($permissionMap)) {
            $this->addImport($controller, 'Illuminate\Support\Facades\Gate');
            $this->addImport($controller, 'App\Enums\Permission');
        }

        $stub = parent::populateStub($stub, $controller);
        $stub = $this->normalizeNotificationNamespaces($stub);

        $modelName = Str::singular($controller->prefix());
        $modelClass = Str::studly($modelName);
        $stub = $this->replaceModelCollectionQueries($stub, $modelClass);

        if (! empty($permissionMap)) {
            $stub = $this->injectPermissionGates($stub, $permissionMap);
        }

        return $stub;
    }

    protected function ensureNotificationImports(Controller $controller): void
    {
        foreach ($controller->methods() as $statements) {
            foreach ($statements as $statement) {
                if (! $statement instanceof SendStatement || ! $statement->isNotification()) {
                    continue;
                }

                $this->addImport($controller, config('blueprint.namespace').'\\Notifications\\'.$statement->mail());

                if ($statement->type() === SendStatement::TYPE_NOTIFICATION_WITH_FACADE) {
                    $this->addImport($controller, 'Illuminate\\Support\\Facades\\Notification');
                }
            }
        }
    }

    protected function hasIndexCollectionQuery(Controller $controller): bool
    {
        $indexStatements = $controller->methods()['index'] ?? [];

        foreach ($indexStatements as $statement) {
            if (! $statement instanceof QueryStatement) {
                continue;
            }

            if (in_array($statement->operation(), ['all', 'get', 'paginate'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeNotificationNamespaces(string $stub): string
    {
        $legacyImport = 'use '.config('blueprint.namespace').'\\Notification\\';
        $newImport = 'use '.config('blueprint.namespace').'\\Notifications\\';

        return str_replace($legacyImport, $newImport, $stub);
    }

    protected function replaceModelCollectionQueries(string $stub, string $modelClass): string
    {
        $modelPattern = preg_quote($modelClass, '/');

        $stub = preg_replace_callback(
            '/(\n[ \t]*)(\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*)'.$modelPattern.'::(?:all|get)\(\)\s*;/',
            fn (array $matches): string => $this->buildQueryBuilderReplacement($matches[1], $matches[2], $modelClass, false),
            $stub,
        ) ?? $stub;

        return preg_replace_callback(
            '/(\n[ \t]*)(\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*)'.$modelPattern.'::paginate\(\)\s*;/',
            fn (array $matches): string => $this->buildQueryBuilderReplacement($matches[1], $matches[2], $modelClass, true),
            $stub,
        ) ?? $stub;
    }

    protected function buildQueryBuilderReplacement(string $linePrefix, string $assignment, string $modelClass, bool $paginate): string
    {
        $operation = $paginate ? 'paginate' : 'get';

        return $linePrefix.$assignment."QueryBuilder::for({$modelClass}::class)"
            .$linePrefix.'    ->allowedFilters(...[])'
            .$linePrefix.'    ->allowedSorts(...[])'
            .$linePrefix."    ->{$operation}();";
    }

    /**
     * Build a map of controller method → Permission enum case name.
     *
     * Only includes methods whose corresponding Permission case actually exists.
     *
     * @return array<string, string> e.g. ['index' => 'VIEW_VEHICLES', 'store' => 'CREATE_VEHICLES']
     */
    protected function buildPermissionMap(Controller $controller): array
    {
        $modelName = Str::studly(Str::singular($controller->prefix()));
        $prefix = $this->resolvePermissionPrefix($modelName);
        $validCases = array_map(fn (Permission $case): string => $case->name, Permission::cases());

        $map = [];

        foreach (array_keys($controller->methods()) as $method) {
            $action = self::METHOD_TO_ACTION[$method] ?? null;

            if ($action === null) {
                continue;
            }

            $caseName = $action.'_'.$prefix;

            if (in_array($caseName, $validCases, true)) {
                $map[$method] = $caseName;
            }
        }

        return $map;
    }

    /**
     * Resolve the Permission enum prefix for a given model name.
     *
     * Uses explicit overrides for non-standard mappings, otherwise derives
     * the prefix by convention: Str::upper(Str::snake(Str::plural($modelName))).
     */
    protected function resolvePermissionPrefix(string $modelName): string
    {
        return self::PERMISSION_PREFIX_OVERRIDES[$modelName]
            ?? Str::upper(Str::snake(Str::plural($modelName)));
    }

    /**
     * Inject Gate::authorize() calls at the start of each method body.
     *
     * @param  array<string, string>  $permissionMap  method → Permission case name
     */
    protected function injectPermissionGates(string $stub, array $permissionMap): string
    {
        foreach ($permissionMap as $methodName => $caseName) {
            $gate = self::INDENT."Gate::authorize(Permission::{$caseName}->value);";

            $stub = preg_replace(
                '/(public function '.preg_quote($methodName, '/').'\([^)]*\)[^{]*\{)\n/',
                "$1\n{$gate}\n",
                $stub,
                1,
            ) ?? $stub;
        }

        return $stub;
    }
}
