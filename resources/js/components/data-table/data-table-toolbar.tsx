import { Search, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { DataTableFacetedFilter } from './data-table-faceted-filter';
import { DataTableViewOptions } from './data-table-view-options';

import type { Table } from '@tanstack/react-table';
import type { FilterDefinition } from '@/types';

interface DataTableToolbarProps<TData> {
    table: Table<TData>;
    search: string;
    onSearchChange: (value: string) => void;
    searchPlaceholder?: string;
    filters?: FilterDefinition[];
    activeFilters?: Record<string, string[]>;
    onFilterChange?: (name: string, values: string[]) => void;
    onClearFilters?: () => void;
    actions?: React.ReactNode;
    /**
     * Arbitrary filter-style controls rendered inline after the
     * faceted-filter map. Intended for components that match the
     * toolbar visual language but don't fit the FilterDefinition shape
     * (e.g. DataTableDateRangeFilter).
     */
    extraFilters?: React.ReactNode;
    /**
     * Preset-style action buttons rendered inline after `extraFilters`
     * and before the "Limpiar" button. Used to expose shortcuts that
     * SET filters rather than being filters themselves (e.g.
     * "Hoy" / "Esta semana" on the services index).
     */
    leadingActions?: React.ReactNode;
}

export function DataTableToolbar<TData>({
    table,
    search,
    onSearchChange,
    searchPlaceholder = 'Buscar...',
    filters,
    activeFilters,
    onFilterChange,
    onClearFilters,
    actions,
    extraFilters,
    leadingActions,
}: DataTableToolbarProps<TData>) {
    'use no memo';
    const hasActiveFilters =
        activeFilters && Object.values(activeFilters).some((v) => v.length > 0);
    const hasFilterRow =
        (filters?.length ?? 0) > 0 || extraFilters !== undefined;

    return (
        <div className="flex flex-col gap-2">
            <div className="@container/toolbar flex items-start justify-between gap-4">
                <div className="relative min-w-37.5 flex-1 lg:min-w-62.5">
                    <Search className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        placeholder={searchPlaceholder}
                        value={search}
                        onChange={(e) => onSearchChange(e.target.value)}
                        className="h-8 pl-8"
                    />
                </div>
                <Separator orientation="vertical" className="h-8!" />
                <div className="flex items-center gap-2">
                    <DataTableViewOptions table={table} />
                    {actions}
                </div>
            </div>
            {hasFilterRow && (
                <Separator label="Filtros:" labelPosition="start" />
            )}
            {hasFilterRow && (
                <div className="scroll-fade-x flex items-center gap-2 overflow-y-hidden *:shrink-0">
                    {filters?.map((filter) => (
                        <DataTableFacetedFilter
                            key={filter.name}
                            name={filter.name}
                            label={filter.label}
                            options={filter.options}
                            selected={activeFilters?.[filter.name] ?? []}
                            onSelectionChange={(values) =>
                                onFilterChange?.(filter.name, values)
                            }
                            capitalizeOptions={filter.capitalizeOptions}
                            sectioned={filter.sectioned}
                        />
                    ))}
                    {extraFilters}
                </div>
            )}
            {leadingActions && (
                <Separator label="Presets:" labelPosition="start" />
            )}
            {leadingActions && (
                <div className="flex flex-wrap items-center gap-2">
                    {leadingActions}
                </div>
            )}
            {hasActiveFilters && (
                <div>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 px-2 lg:px-3"
                        onClick={onClearFilters}
                    >
                        Limpiar
                        <X className="ml-2 size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}
