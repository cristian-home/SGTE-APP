import { Link, router } from '@inertiajs/react';
import { Can } from '@/components/can';
import {
    DataTableColumnHeader,
    DataTableRowActions,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { type EditableVehicle } from '@/components/vehicles/vehicle-dialog';
import { VehicleDocumentPills } from '@/components/vehicles/vehicle-document-pills';
import { Permission } from '@/enums/Permission';
import { type VehicleType, VehicleTypeLabel } from '@/enums/VehicleType';
import vehicles from '@/routes/vehicles';

import type { ColumnDef, Table } from '@tanstack/react-table';
import type { Vehicle } from '@/types/models';

export interface VehicleTableMeta {
    onEdit: (vehicle: EditableVehicle) => void;
}

function meta(table: Table<Vehicle>): VehicleTableMeta {
    return table.options.meta as VehicleTableMeta;
}

const statusLabels: Record<string, string> = {
    active: 'Activo',
    maintenance: 'En Mantenimiento',
    retired: 'Retirado',
};

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'maintenance':
            return 'secondary';
        default:
            return 'outline';
    }
}

function ownerLabel(vehicle: Vehicle): string {
    if (!vehicle.is_third_party) {
        return 'Empresa';
    }
    const tp = vehicle.third_party;
    if (!tp) {
        return '—';
    }
    if (tp.is_natural_person) {
        return (
            `${tp.first_name ?? ''} ${tp.first_lastname ?? ''}`.trim() || '—'
        );
    }
    return tp.company_name ?? '—';
}

export const columns: ColumnDef<Vehicle, unknown>[] = [
    {
        accessorKey: 'plate',
        meta: { label: 'Placa' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Placa" />
        ),
        cell: ({ row }) => (
            <Link
                href={vehicles.show(row.original.id).url}
                className="font-mono text-primary hover:underline"
            >
                {row.original.plate}
            </Link>
        ),
    },
    {
        accessorKey: 'internal_code',
        meta: { label: 'Cód. Interno' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Cód. Interno" />
        ),
        cell: ({ row }) => (
            <span className="font-mono text-sm">
                {row.original.internal_code}
            </span>
        ),
    },
    {
        id: 'type',
        accessorKey: 'type',
        meta: { label: 'Tipo' },
        header: 'Tipo',
        cell: ({ row }) =>
            VehicleTypeLabel[row.original.type as VehicleType] ??
            row.original.type,
    },
    {
        id: 'propietario',
        meta: { label: 'Propietario' },
        header: 'Propietario',
        cell: ({ row }) => (
            <span className="text-sm">{ownerLabel(row.original)}</span>
        ),
    },
    {
        accessorKey: 'status',
        meta: { label: 'Estado' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Estado" />
        ),
        cell: ({ row }) => (
            <Badge variant={statusVariant(row.original.status)}>
                {statusLabels[row.original.status] ?? row.original.status}
            </Badge>
        ),
    },
    {
        id: 'documentos',
        meta: { label: 'Documentos' },
        header: 'Documentos',
        cell: ({ row }) => <VehicleDocumentPills vehicle={row.original} />,
    },
    {
        id: 'actions',
        cell: ({ row, table }) => {
            const vehicle = row.original;
            return (
                <Can permission={Permission.DELETE_VEHICLES}>
                    <DataTableRowActions
                        onEdit={() => meta(table).onEdit(vehicle)}
                        onDelete={() =>
                            router.delete(vehicles.destroy(vehicle.id).url, {
                                preserveScroll: true,
                            })
                        }
                    />
                </Can>
            );
        },
    },
];
