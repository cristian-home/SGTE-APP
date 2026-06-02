export type FilterOption = {
    value: string;
    label: string;
    icon?: React.ComponentType<{ className?: string }>;
    /**
     * Records matching this value under the other active filters (the
     * facet's own selection is excluded). Drives the count badge and the
     * "with records / others" split. Undefined → no count UI.
     */
    count?: number;
};

export type FilterDefinition = {
    /** Spatie QueryBuilder filter name (matches `filter[name]=...` in URL). */
    name: string;
    /** Display label for the filter button. */
    label: string;
    /** Available options to choose from. */
    options: FilterOption[];
    /** Capitalize each option's label (data stored lowercase, e.g. cities). */
    capitalizeOptions?: boolean;
    /**
     * High-cardinality facet: list values with records first, collapse the
     * rest (count 0) behind an "Otras (N)" toggle. Requires `count` on the
     * options.
     */
    sectioned?: boolean;
};
