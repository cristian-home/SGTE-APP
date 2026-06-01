import { Head, router } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/data-table';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { Button } from '@/components/ui/button';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';
import serviceIncidents from '@/routes/service-incidents';

import {
    columns,
    incidentSeverityRowTint,
    type ServiceIncidentRow,
} from './columns';

import type { Row } from '@tanstack/react-table';
import type { BreadcrumbItem, FilterDefinition, PaginatedData } from '@/types';

interface IncidentTypeOption {
    id: number;
    code: string;
    name: string;
    severity: string;
    affects_billing_default: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Novedades', href: serviceIncidents.index().url },
];

function rowTintFor(row: Row<ServiceIncidentRow>): string | undefined {
    return incidentSeverityRowTint(
        row.original.incident_type?.severity ?? null,
    );
}

const currencyFormatter = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

export default function ServiceIncidentsIndex({
    serviceIncidents: paginatedIncidents,
    incidentTypes,
    filteredBillingTotal,
}: {
    serviceIncidents: PaginatedData<ServiceIncidentRow>;
    incidentTypes: IncidentTypeOption[];
    filteredBillingTotal?: number;
}) {
    'use no memo';
    // Mirrors the controller prop so subsequent filter changes (which
    // refetch via fetch() inside useServerTable, not a full Inertia
    // re-render) can update the footer total via the onResponse hook.
    const [billingTotal, setBillingTotal] = useState<number>(
        filteredBillingTotal ?? 0,
    );
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
    } = useServerTable<ServiceIncidentRow>({
        data: paginatedIncidents,
        columns,
        onResponse: (json) => {
            const value = json.filtered_billing_total;
            setBillingTotal(
                typeof value === 'number' ? value : Number(value) || 0,
            );
        },
    });

    const incidentFilters: FilterDefinition[] = [
        {
            name: 'incident_type_id',
            label: 'Tipo',
            options: incidentTypes.map((t) => ({
                value: String(t.id),
                label: t.name,
            })),
        },
        {
            name: 'severity',
            label: 'Severidad',
            options: [
                { value: 'informational', label: 'Informativo' },
                { value: 'minor', label: 'Menor' },
                { value: 'major', label: 'Mayor' },
            ],
        },
        {
            name: 'is_driver_report',
            label: 'Reporte del conductor',
            options: [
                { value: '1', label: 'Sí' },
                { value: '0', label: 'No' },
            ],
        },
        {
            name: 'affects_billing',
            label: 'Afecta facturación',
            options: [
                { value: '1', label: 'Sí' },
                { value: '0', label: 'No' },
            ],
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novedades" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por descripción..."
                    filters={incidentFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    getRowClassName={rowTintFor}
                    actions={
                        <Button
                            onClick={() =>
                                router.visit(serviceIncidents.create().url)
                            }
                            size="sm"
                            aria-label="Crear Novedad"
                        >
                            <PlusIcon className="size-4" />
                            <ToolbarLabel>Crear Novedad</ToolbarLabel>
                        </Button>
                    }
                />
                {billingTotal > 0 && (
                    <div className="flex items-center justify-end gap-3 rounded-md border bg-muted/30 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">
                            Total recargo de novedades en el filtro actual:
                        </span>
                        <span className="font-bold tabular-nums">
                            {currencyFormatter.format(billingTotal)}
                        </span>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
