import {
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { DataTablePagination } from './data-table-pagination';
import { DataTableToolbar } from './data-table-toolbar';

import type {
    ColumnDef,
    Row,
    SortingState,
    Table as TanStackTable,
} from '@tanstack/react-table';
import type { FilterDefinition, PaginatedData } from '@/types';

interface ServerSideProps<TData> {
    table: TanStackTable<TData>;
    paginatedData: PaginatedData<TData>;
    search: string;
    onSearchChange: (value: string) => void;
    loading?: boolean;
    onNavigate: (url: string) => void;
    onPerPageChange: (perPage: string) => void;
    searchPlaceholder?: string;
    filters?: FilterDefinition[];
    activeFilters?: Record<string, string[]>;
    onFilterChange?: (name: string, values: string[]) => void;
    onClearFilters?: () => void;
    actions?: React.ReactNode;
    /**
     * Toolbar-style controls rendered inline after the faceted filters.
     * Use this to inject components that match the toolbar visual
     * language but don't fit the FilterDefinition shape (e.g. the
     * DataTableDateRangeFilter on the services index).
     */
    extraFilters?: React.ReactNode;
    /**
     * Preset-style action buttons rendered inline after `extraFilters`
     * and before the "Limpiar" button. Intended for shortcuts that
     * SET filters rather than being filters themselves.
     */
    leadingActions?: React.ReactNode;
    /**
     * Optional row-level classname hook. Returns a className string (or
     * undefined) to apply to the <TableRow>. Useful for tinting rows by
     * domain state — e.g., the vehicles index tints rows whose documents
     * are expired or expiring soon.
     */
    getRowClassName?: (row: Row<TData>) => string | undefined;
}

interface ClientSideProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    searchKey?: string;
    searchPlaceholder?: string;
    toolbar?: React.ReactNode;
}

type DataTableProps<TData, TValue> =
    | ServerSideProps<TData>
    | ClientSideProps<TData, TValue>;

function isServerSide<TData, TValue>(
    props: DataTableProps<TData, TValue>,
): props is ServerSideProps<TData> {
    return 'table' in props;
}

export function DataTable<TData, TValue>(props: DataTableProps<TData, TValue>) {
    if (isServerSide(props)) {
        return <ServerSideDataTable {...props} />;
    }

    return <ClientSideDataTable {...props} />;
}

function ServerSideDataTable<TData>({
    table,
    paginatedData,
    search,
    onSearchChange,
    loading = false,
    onNavigate,
    onPerPageChange,
    searchPlaceholder = 'Buscar...',
    filters,
    activeFilters,
    onFilterChange,
    onClearFilters,
    actions,
    extraFilters,
    leadingActions,
    getRowClassName,
}: ServerSideProps<TData>) {
    'use no memo';

    return (
        <div className="space-y-4">
            <DataTableToolbar
                table={table}
                search={search}
                onSearchChange={onSearchChange}
                searchPlaceholder={searchPlaceholder}
                filters={filters}
                activeFilters={activeFilters}
                onFilterChange={onFilterChange}
                onClearFilters={onClearFilters}
                actions={actions}
                extraFilters={extraFilters}
                leadingActions={leadingActions}
            />

            <div
                className={
                    loading ? 'pointer-events-none opacity-50' : undefined
                }
            >
                <DataTableBody
                    table={table}
                    getRowClassName={getRowClassName}
                />
            </div>

            <DataTablePagination
                pagination={paginatedData}
                onNavigate={onNavigate}
                onPerPageChange={onPerPageChange}
            />
        </div>
    );
}

function ClientSideDataTable<TData, TValue>({
    columns,
    data,
    searchKey,
    searchPlaceholder = 'Buscar...',
    toolbar,
}: ClientSideProps<TData, TValue>) {
    'use no memo';
    const [sorting, setSorting] = useState<SortingState>([]);
    const [globalFilter, setGlobalFilter] = useState('');

    // eslint-disable-next-line react-hooks/incompatible-library
    const table = useReactTable({
        data,
        columns,
        state: {
            sorting,
            globalFilter: searchKey ? globalFilter : undefined,
        },
        onSortingChange: setSorting,
        onGlobalFilterChange: searchKey ? setGlobalFilter : undefined,
        globalFilterFn: searchKey
            ? (row, _columnId, filterValue) => {
                  const value = row.getValue(searchKey);
                  return String(value)
                      .toLowerCase()
                      .includes(String(filterValue).toLowerCase());
              }
            : undefined,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: searchKey ? getFilteredRowModel() : undefined,
    });

    return (
        <div className="space-y-4">
            {(searchKey || toolbar) && (
                <div className="flex items-center justify-between gap-4">
                    {searchKey && (
                        <Input
                            placeholder={searchPlaceholder}
                            value={globalFilter}
                            onChange={(e) => setGlobalFilter(e.target.value)}
                            className="max-w-sm"
                        />
                    )}
                    {toolbar && <div className="ml-auto">{toolbar}</div>}
                </div>
            )}

            <DataTableBody table={table} />
        </div>
    );
}

function DataTableBody<TData>({
    table,
    getRowClassName,
}: {
    table: TanStackTable<TData>;
    getRowClassName?: (row: Row<TData>) => string | undefined;
}) {
    'use no memo';
    const columnCount = table.getAllColumns().length;

    return (
        <div className="overflow-hidden rounded-md border">
            <Table>
                <TableHeader className="bg-muted">
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => (
                                <TableHead key={header.id}>
                                    {header.isPlaceholder
                                        ? null
                                        : flexRender(
                                              header.column.columnDef.header,
                                              header.getContext(),
                                          )}
                                </TableHead>
                            ))}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {table.getRowModel().rows.length ? (
                        table.getRowModel().rows.map((row) => (
                            <TableRow
                                key={row.id}
                                className={cn(getRowClassName?.(row))}
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell key={cell.id}>
                                        {flexRender(
                                            cell.column.columnDef.cell,
                                            cell.getContext(),
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : (
                        <TableRow>
                            <TableCell
                                colSpan={columnCount}
                                className="h-24 text-center"
                            >
                                Sin resultados.
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}
