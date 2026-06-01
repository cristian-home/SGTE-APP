import { Head, Link } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { DataTable } from '@/components/data-table';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { Button } from '@/components/ui/button';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';

import { fuecColumns, type FuecRow } from './columns';

import type { BreadcrumbItem, FilterDefinition, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'FUEC', href: '/fuecs' }];

const fuecFilters: FilterDefinition[] = [
    {
        name: 'status',
        label: 'Estado',
        options: [
            { value: 'active', label: 'Vigente' },
            { value: 'cancelled', label: 'Anulado' },
        ],
    },
];

export default function FuecsIndex({
    fuecs,
}: {
    fuecs: PaginatedData<FuecRow>;
}) {
    'use no memo';
    const {
        table,
        paginatedData,
        search,
        setSearch,
        loading,
        onNavigate,
        onPerPageChange,
        activeFilters,
        setFilter,
        clearFilters,
    } = useServerTable<FuecRow>({ data: fuecs, columns: fuecColumns });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="FUEC" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por consecutivo..."
                    filters={fuecFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    actions={
                        <Button asChild size="sm">
                            <Link
                                href="/fuecs/create"
                                aria-label="Generar FUEC"
                            >
                                <PlusIcon className="size-4" />
                                <ToolbarLabel>Generar FUEC</ToolbarLabel>
                            </Link>
                        </Button>
                    }
                />
            </div>
        </AppLayout>
    );
}
