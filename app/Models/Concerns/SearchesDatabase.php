<?php

namespace App\Models\Concerns;

use App\Support\SearchField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

trait SearchesDatabase
{
    /**
     * Columns to search. Each entry is one of:
     * - A plain column name (string) — defaults to fuzzy matching.
     * - A composite array of column names — concatenated with spaces, fuzzy.
     * - A `SearchField` descriptor declaring an explicit per-field mode
     *   (exact / prefix / substring / fuzzy). Columns may use dot notation
     *   (`relation.column`) to search a `belongsTo` relation.
     *
     * @return array<int, string|array<int, string>|SearchField>
     */
    abstract public function searchableColumns(): array;

    /**
     * Override in model to customize fuzzy match sensitivity (0.0–1.0).
     * Lower = more permissive, higher = stricter.
     */
    public function searchSimilarityThreshold(): float
    {
        return 0.3;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $fields = $this->normalizedSearchFields();

        if ($term === '' || $fields === []) {
            return $query;
        }

        $usesTrigram = $query->getConnection()->getDriverName() === 'pgsql';

        return $query->where(function (Builder $q) use ($fields, $term, $usesTrigram) {
            foreach ($fields as $field) {
                $this->applySearchField($q, $field, $term, $usesTrigram);
            }
        });
    }

    /**
     * Search with results ordered by relevance (best similarity score first).
     * Only fuzzy fields contribute to the ordering. On non-PostgreSQL drivers,
     * falls back to search() without ordering.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearchWithRelevance(Builder $query, string $term): Builder
    {
        $this->scopeSearch($query, $term);

        $term = trim($term);
        $fields = $this->normalizedSearchFields();

        if ($term === '' || $fields === [] || $query->getConnection()->getDriverName() !== 'pgsql') {
            return $query;
        }

        return $this->addRelevanceOrdering($query, $term, $fields);
    }

    /**
     * Normalize the raw `searchableColumns()` entries into SearchField
     * descriptors. Plain strings and arrays default to fuzzy matching to
     * preserve backward compatibility.
     *
     * @return array<int, SearchField>
     */
    private function normalizedSearchFields(): array
    {
        return array_map(
            fn ($entry): SearchField => $entry instanceof SearchField ? $entry : SearchField::fuzzy($entry),
            $this->searchableColumns()
        );
    }

    /**
     * Add one field's predicate as an OR branch of the global search.
     *
     * @param  Builder<static>  $query
     */
    private function applySearchField(Builder $query, SearchField $field, string $term, bool $usesTrigram): void
    {
        [$relation, $columns] = $this->splitRelation($field->columns);
        $threshold = $this->searchSimilarityThreshold();

        if ($relation !== null) {
            $query->orWhereHas($relation, function (Builder $sub) use ($columns, $term, $field, $usesTrigram, $threshold) {
                $expression = $this->expression($sub->getGrammar(), $columns);
                $this->applyModePredicate($sub, $expression, $term, $field->mode, $usesTrigram, $threshold);
            });

            return;
        }

        $expression = $this->expression($query->getGrammar(), $columns);

        $query->orWhere(function (Builder $inner) use ($expression, $term, $field, $usesTrigram, $threshold) {
            $this->applyModePredicate($inner, $expression, $term, $field->mode, $usesTrigram, $threshold);
        });
    }

    /**
     * Build the WHERE condition for a single field according to its mode.
     * `$expression` is a SQL string already wrapped/coalesced (single column
     * or composite concatenation), safe to interpolate.
     *
     * @param  Builder<static>  $query
     */
    private function applyModePredicate(Builder $query, string $expression, string $term, string $mode, bool $usesTrigram, float $threshold): void
    {
        $like = $usesTrigram ? 'ilike' : 'like';

        switch ($mode) {
            case SearchField::EXACT:
                $query->whereRaw("lower({$expression}) = lower(?)", [$term]);
                break;

            case SearchField::PREFIX:
                $query->whereRaw("{$expression} {$like} ?", ["{$term}%"]);
                break;

            case SearchField::SUBSTRING:
                $query->whereRaw("{$expression} {$like} ?", ["%{$term}%"]);
                break;

            case SearchField::FUZZY:
            default:
                $query->whereRaw("{$expression} {$like} ?", ["%{$term}%"]);
                if ($usesTrigram) {
                    $query->orWhereRaw("word_similarity(?, {$expression}) >= ?", [$term, $threshold]);
                }
                break;
        }
    }

    /**
     * @param  Builder<static>  $query
     * @param  array<int, SearchField>  $fields
     * @return Builder<static>
     */
    private function addRelevanceOrdering(Builder $query, string $term, array $fields): Builder
    {
        $grammar = $query->getGrammar();
        $scores = [];
        $bindings = [];

        foreach ($fields as $field) {
            if (! $field->isFuzzy()) {
                continue;
            }

            [$relation, $columns] = $this->splitRelation($field->columns);

            if ($relation !== null) {
                $scores[] = $this->buildRelationScoreExpression($grammar, $relation, $columns);
            } else {
                $scores[] = 'word_similarity(?, '.$this->expression($grammar, $columns).')';
            }

            $bindings[] = $term;
        }

        if ($scores === []) {
            return $query;
        }

        $greatest = 'GREATEST('.implode(', ', $scores).')';

        return $query->orderByRaw("{$greatest} DESC", $bindings);
    }

    /**
     * Split columns into an optional `belongsTo` relation name and the bare
     * field names. Dot-notation columns must all target the same relation.
     *
     * @param  array<int, string>  $columns
     * @return array{string|null, array<int, string>}
     */
    private function splitRelation(array $columns): array
    {
        $relation = null;
        $fields = [];

        foreach ($columns as $column) {
            if (str_contains($column, '.')) {
                [$rel, $field] = explode('.', $column, 2);
                $relation ??= $rel;
                $fields[] = $field;
            } else {
                $fields[] = $column;
            }
        }

        return [$relation, $fields];
    }

    /**
     * Build a coalesced SQL expression for one or more columns. A single
     * column becomes `coalesce(col, '')`; multiple columns are concatenated
     * with spaces, enabling multi-field matching.
     *
     * @param  array<int, string>  $fields
     */
    private function expression(Grammar $grammar, array $fields): string
    {
        if (count($fields) === 1) {
            return "coalesce({$grammar->wrap($fields[0])}, '')";
        }

        return $this->buildConcatExpression($grammar, $fields);
    }

    /**
     * Build a SQL expression that concatenates fields with spaces.
     *
     * @param  array<int, string>  $fields
     */
    private function buildConcatExpression(Grammar $grammar, array $fields): string
    {
        $parts = array_map(
            fn (string $field) => "coalesce({$grammar->wrap($field)}, '')",
            $fields
        );

        return implode(" || ' ' || ", $parts);
    }

    /**
     * Build a correlated subquery that computes the word_similarity score
     * for columns on a BelongsTo relationship.
     *
     * @param  array<int, string>  $fields
     */
    private function buildRelationScoreExpression(Grammar $grammar, string $relation, array $fields): string
    {
        $belongsTo = $this->$relation();
        $relatedTable = $grammar->wrapTable($belongsTo->getRelated()->getTable());
        $ownerKey = $grammar->wrap($belongsTo->getOwnerKeyName());
        $foreignKey = $grammar->wrap($belongsTo->getForeignKeyName());
        $parentTable = $grammar->wrapTable($this->getTable());

        $concat = $this->expression($grammar, $fields);

        return "coalesce((SELECT word_similarity(?, {$concat}) FROM {$relatedTable} WHERE {$relatedTable}.{$ownerKey} = {$parentTable}.{$foreignKey}), 0)";
    }
}
