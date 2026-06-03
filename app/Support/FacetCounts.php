<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Computes per-value record counts for high-cardinality faceted filters
 * (Ciudad, Cliente, etc.) so the DataTable can show a count badge next to
 * each value and split "with records" from "others".
 *
 * Each facet's counts respect every OTHER active filter (and the search)
 * but exclude the facet's own selection — standard faceted-search
 * semantics, so a multi-select within a facet stays additive and the user
 * sees what adding a value would yield.
 */
class FacetCounts
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<int, mixed>  $allowedFilters  the controller's allowedFilters() definition (shared with index())
     * @param  array<string, string>  $facets  filter name => groupable DB column
     * @return array<string, array<string, int>> filter name => [value => count]
     */
    public static function for(string $model, array $allowedFilters, Request $request, array $facets): array
    {
        $filter = (array) $request->query('filter', []);
        $result = [];

        foreach ($facets as $name => $column) {
            // Re-run the table query with this facet's own filter removed
            // (keep search + every other filter), grouped by the column.
            $facetRequest = $request->duplicate();
            $facetRequest->query->set('filter', Arr::except($filter, $name));

            $counts = QueryBuilder::for($model::query(), $facetRequest)
                ->allowedFilters(...$allowedFilters)
                ->reorder()
                ->select($column)
                ->selectRaw('count(*) as aggregate')
                ->groupBy($column)
                ->pluck('aggregate', $column);

            $result[$name] = $counts
                ->reject(fn ($count, $value) => $value === null || $value === '')
                ->mapWithKeys(fn ($count, $value) => [(string) $value => (int) $count])
                ->all();
        }

        return $result;
    }
}
