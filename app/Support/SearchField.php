<?php

namespace App\Support;

/**
 * Declares how a single column (or a composite group of columns) participates
 * in a model's global database search. The same search term is matched against
 * every field, but each field chooses its own strategy:
 *
 * - `exact`     — case-insensitive equality (`lower(col) = lower(term)`).
 * - `prefix`    — starts-with match (`col ILIKE 'term%'`).
 * - `substring` — contains match (`col ILIKE '%term%'`), no fuzzy noise.
 * - `fuzzy`     — substring OR PostgreSQL trigram similarity; the only mode
 *                 that contributes to relevance ordering.
 *
 * Use `substring`/`prefix`/`exact` for codes, identifiers and consecutivos
 * (plate, internal code, invoice/contract number, NIT), and `fuzzy` for free
 * text (names, addresses, descriptions). See `App\Models\Concerns\SearchesDatabase`.
 */
final class SearchField
{
    public const EXACT = 'exact';

    public const PREFIX = 'prefix';

    public const SUBSTRING = 'substring';

    public const FUZZY = 'fuzzy';

    /**
     * @param  array<int, string>  $columns  one column, or several to concatenate (composite)
     */
    private function __construct(
        public readonly array $columns,
        public readonly string $mode,
    ) {}

    /**
     * @param  string|array<int, string>  $columns
     */
    public static function exact(string|array $columns): self
    {
        return new self((array) $columns, self::EXACT);
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    public static function prefix(string|array $columns): self
    {
        return new self((array) $columns, self::PREFIX);
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    public static function substring(string|array $columns): self
    {
        return new self((array) $columns, self::SUBSTRING);
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    public static function fuzzy(string|array $columns): self
    {
        return new self((array) $columns, self::FUZZY);
    }

    public function isFuzzy(): bool
    {
        return $this->mode === self::FUZZY;
    }
}
