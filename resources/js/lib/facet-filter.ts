import type { FilterOption } from '@/types';

/** `filter name => { option value => record count }`, from the controller. */
export type FacetCounts = Record<string, Record<string, number>>;

/**
 * Attach per-value record counts (from the backend FacetCounts payload) to
 * a high-cardinality filter's options. Missing values default to 0 so the
 * faceted filter can section them into "Otras".
 */
export function withCounts(
    options: FilterOption[],
    counts: Record<string, number> | undefined,
): FilterOption[] {
    return options.map((option) => ({
        ...option,
        count: counts?.[option.value] ?? 0,
    }));
}
