import { Head, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import VehicleTypeController from '@/actions/App/Http/Controllers/VehicleTypeController';
import {
    DataTable,
    DataTableColumnHeader,
    DataTableRowActions,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import VehicleTypeDialog from '@/components/vehicle-types/vehicle-type-dialog';
import AppLayout from '@/layouts/app-layout';
import type { ColumnDef } from '@tanstack/react-table';
import type { BreadcrumbItem, VehicleType } from '@/types';

export interface LicenseCategoryOption {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tipos de Vehículo',
        href: VehicleTypeController.index.url(),
    },
];

const columns: ColumnDef<VehicleType>[] = [
    {
        accessorKey: 'code',
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Código" />
        ),
        cell: ({ row }) => (
            <span className="font-mono">{row.getValue('code')}</span>
        ),
    },
    {
        accessorKey: 'name',
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Nombre" />
        ),
    },
    {
        id: 'allowed_license_categories',
        header: 'Licencias',
        cell: ({ row }) => {
            const cats = row.original.allowed_license_categories ?? [];
            return cats.length === 0 ? (
                <span className="text-muted-foreground">—</span>
            ) : (
                <span className="flex flex-wrap gap-1">
                    {cats.map((c) => (
                        <Badge key={c} variant="outline">
                            {c}
                        </Badge>
                    ))}
                </span>
            );
        },
    },
    {
        accessorKey: 'active',
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Activo" />
        ),
        cell: ({ row }) =>
            row.getValue('active') ? (
                <Check className="size-4 text-green-600" />
            ) : (
                <X className="size-4 text-muted-foreground" />
            ),
    },
    {
        accessorKey: 'vehicles_count',
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Vehículos" />
        ),
        cell: ({ row }) => row.original.vehicles_count ?? 0,
    },
];

export default function VehicleTypesIndex({
    vehicleTypes,
    licenseCategories,
}: {
    vehicleTypes: VehicleType[];
    licenseCategories: LicenseCategoryOption[];
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogMode, setDialogMode] = useState<'create' | 'edit'>('create');
    const [selected, setSelected] = useState<VehicleType | null>(null);

    function openCreate() {
        setSelected(null);
        setDialogMode('create');
        setDialogOpen(true);
    }

    function openEdit(record: VehicleType) {
        setSelected(record);
        setDialogMode('edit');
        setDialogOpen(true);
    }

    const columnsWithActions: ColumnDef<VehicleType>[] = [
        ...columns,
        {
            id: 'actions',
            cell: ({ row }) => (
                <DataTableRowActions
                    onEdit={() => openEdit(row.original)}
                    onDelete={() =>
                        router.delete(
                            VehicleTypeController.destroy.url(row.original.id),
                            { preserveScroll: true },
                        )
                    }
                />
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tipos de Vehículo" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <PageHeader
                    title="Tipos de Vehículo"
                    onCreate={openCreate}
                    createLabel="Nuevo Tipo de Vehículo"
                />
                <DataTable
                    columns={columnsWithActions}
                    data={vehicleTypes}
                    searchKey="name"
                    searchPlaceholder="Buscar por nombre..."
                />
            </div>
            <VehicleTypeDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                mode={dialogMode}
                vehicleType={selected}
                licenseCategories={licenseCategories}
            />
        </AppLayout>
    );
}
