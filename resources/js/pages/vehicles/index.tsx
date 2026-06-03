import { Head } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import { Button } from '@/components/ui/button';
import VehicleDialog, {
    type EditableVehicle,
} from '@/components/vehicles/vehicle-dialog';
import { vehicleDocsAggregateStatus } from '@/components/vehicles/vehicle-document-pills';
import { type VehicleTypeOption } from '@/components/vehicles/vehicle-form';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';
import { type FacetCounts, withCounts } from '@/lib/facet-filter';
import vehicles from '@/routes/vehicles';

import { columns, type VehicleTableMeta } from './columns';

import type { Row } from '@tanstack/react-table';
import type { BreadcrumbItem, FilterDefinition, PaginatedData } from '@/types';
import type { Vehicle } from '@/types/models';

interface ThirdPartyOption {
    id: number;
    identification_number: string;
    first_name: string | null;
    first_lastname: string | null;
    company_name: string | null;
    is_natural_person: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Vehículos',
        href: vehicles.index().url,
    },
];

const STATIC_VEHICLE_FILTERS: FilterDefinition[] = [
    {
        name: 'status',
        label: 'Estado',
        options: [
            { value: 'active', label: 'Activo' },
            { value: 'maintenance', label: 'En Mantenimiento' },
            { value: 'retired', label: 'Retirado' },
        ],
    },
    {
        name: 'docs_status',
        label: 'Documentos',
        options: [
            { value: 'ok', label: 'Al día' },
            { value: 'expiring_soon', label: 'Por vencer (≤30 días)' },
            { value: 'expired', label: 'Vencidos' },
        ],
    },
    {
        name: 'soat_expired',
        label: 'SOAT vencido',
        options: [{ value: 'true', label: 'Sí' }],
    },
    {
        name: 'rtm_expired',
        label: 'RTM vencido',
        options: [{ value: 'true', label: 'Sí' }],
    },
    {
        name: 'operation_card_expired',
        label: 'T.O. vencida',
        options: [{ value: 'true', label: 'Sí' }],
    },
];

function rowTintFor(row: Row<Vehicle>): string | undefined {
    const status = vehicleDocsAggregateStatus(row.original);
    if (status === 'expired') {
        return 'bg-destructive/10 hover:bg-destructive/15';
    }
    if (status === 'expiring_soon') {
        return 'bg-amber-100/60 hover:bg-amber-100/80 dark:bg-amber-900/20 dark:hover:bg-amber-900/30';
    }
    return undefined;
}

export default function VehiclesIndex({
    vehicles: paginatedVehicles,
    municipalities,
    thirdParties,
    vehicleTypes,
    facetCounts,
    suggestedInternalCode,
}: {
    vehicles: PaginatedData<Vehicle>;
    municipalities: MunicipalityOption[];
    thirdParties: ThirdPartyOption[];
    vehicleTypes: VehicleTypeOption[];
    facetCounts: FacetCounts;
    suggestedInternalCode: string;
}) {
    'use no memo';
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogMode, setDialogMode] = useState<'create' | 'edit'>('create');
    const [selectedVehicle, setSelectedVehicle] =
        useState<EditableVehicle | null>(null);

    function openCreate() {
        setSelectedVehicle(null);
        setDialogMode('create');
        setDialogOpen(true);
    }

    function openEdit(vehicle: EditableVehicle) {
        setSelectedVehicle(vehicle);
        setDialogMode('edit');
        setDialogOpen(true);
    }

    const tableMeta: VehicleTableMeta = useMemo(
        () => ({ onEdit: openEdit }),
        [],
    );

    const vehicleFilters = useMemo<FilterDefinition[]>(
        () => [
            ...STATIC_VEHICLE_FILTERS,
            {
                name: 'municipality_id',
                label: 'Ciudad',
                options: withCounts(
                    municipalities.map((m) => ({
                        value: String(m.id),
                        label: m.department
                            ? `${m.name} (${m.department.name})`
                            : m.name,
                    })),
                    facetCounts.municipality_id,
                ),
                capitalizeOptions: true,
                sectioned: true,
            },
        ],
        [municipalities, facetCounts],
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
    } = useServerTable<Vehicle>({
        data: paginatedVehicles,
        columns,
        meta: tableMeta,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vehículos" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por placa, código o marca..."
                    filters={vehicleFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    getRowClassName={rowTintFor}
                    actions={
                        <Button
                            onClick={openCreate}
                            size="sm"
                            aria-label="Crear Vehículo"
                        >
                            <PlusIcon className="size-4" />
                            <ToolbarLabel>Crear Vehículo</ToolbarLabel>
                        </Button>
                    }
                />
            </div>

            <VehicleDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                mode={dialogMode}
                vehicle={selectedVehicle}
                municipalities={municipalities}
                thirdParties={thirdParties}
                vehicleTypes={vehicleTypes}
                suggestedInternalCode={suggestedInternalCode}
            />
        </AppLayout>
    );
}
