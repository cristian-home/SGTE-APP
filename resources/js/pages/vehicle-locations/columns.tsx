import { Link, router } from '@inertiajs/react';
import { Eye, Trash2 } from 'lucide-react';
import { Can } from '@/components/can';
import { DataTableColumnHeader } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Permission } from '@/enums/Permission';
import { formatTimestampInViewerTz } from '@/lib/datetime';
import type { ColumnDef } from '@tanstack/react-table';

export interface VehicleLocationRow {
    id: number;
    vehicle_id: number;
    service_id: number | null;
    recorded_at: string | null;
    latitude: string;
    longitude: string;
    accuracy: string | null;
    is_manual: boolean;
    captured_by: number | null;
    vehicle?: { id: number; plate: string } | null;
    service?: { id: number; service_date: string | null } | null;
    captured_by_user?: { id: number; name: string } | null;
    // Laravel serializes `capturedBy` relation as "captured_by" when eager-loaded
    // with the default convention; our controller aliases it.
}

function formatTimestamp(iso: string | null): string {
    return formatTimestampInViewerTz(iso) || '—';
}

type VehicleLocationRowWithCauser = VehicleLocationRow & {
    captured_by_user?: { id: number; name: string } | null;
};

export const vehicleLocationColumns: ColumnDef<
    VehicleLocationRowWithCauser,
    unknown
>[] = [
    {
        accessorKey: 'recorded_at',
        meta: { label: 'Fecha/Hora' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Fecha/Hora" />
        ),
        cell: ({ row }) => (
            <span className="font-mono text-xs whitespace-nowrap">
                {formatTimestamp(row.original.recorded_at)}
            </span>
        ),
    },
    {
        id: 'vehiculo',
        meta: { label: 'Vehículo' },
        header: 'Vehículo',
        cell: ({ row }) => {
            const plate = row.original.vehicle?.plate;
            if (!plate) return <span className="text-muted-foreground">—</span>;
            return (
                <Link
                    href={`/vehicles/${row.original.vehicle_id}`}
                    className="font-mono text-primary hover:underline"
                >
                    {plate}
                </Link>
            );
        },
    },
    {
        id: 'servicio',
        meta: { label: 'Servicio' },
        header: 'Servicio',
        cell: ({ row }) => {
            const service = row.original.service;
            if (!service)
                return <span className="text-muted-foreground">—</span>;
            return (
                <Link
                    href={`/services/${service.id}`}
                    className="text-primary hover:underline"
                >
                    #{service.id}
                </Link>
            );
        },
    },
    {
        id: 'origen',
        meta: { label: 'Origen' },
        header: 'Origen',
        cell: ({ row }) =>
            row.original.is_manual ? (
                <Badge variant="outline">Manual</Badge>
            ) : (
                <Badge>GPS</Badge>
            ),
    },
    {
        id: 'coords',
        meta: { label: 'Coordenadas' },
        header: 'Coordenadas',
        cell: ({ row }) => (
            <span className="font-mono text-xs whitespace-nowrap">
                {row.original.latitude}, {row.original.longitude}
            </span>
        ),
    },
    {
        id: 'precision',
        meta: { label: 'Precisión' },
        header: 'Precisión',
        cell: ({ row }) => {
            const acc = row.original.accuracy;
            if (acc === null || acc === undefined) {
                return <span className="text-muted-foreground">—</span>;
            }
            return <span className="font-mono text-xs">{acc} m</span>;
        },
    },
    {
        id: 'captured_by_user',
        meta: { label: 'Registrado por' },
        header: 'Registrado por',
        cell: ({ row }) => (
            <span>{row.original.captured_by_user?.name ?? 'Sistema'}</span>
        ),
    },
    {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => (
            <div className="flex items-center gap-1">
                <Can permission={Permission.VIEW_VEHICLE_LOCATIONS}>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Ver detalle"
                        asChild
                    >
                        <Link href={`/vehicle-locations/${row.original.id}`}>
                            <Eye className="size-4" />
                        </Link>
                    </Button>
                </Can>
                <Can permission={Permission.DELETE_VEHICLE_LOCATIONS}>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Eliminar"
                        onClick={() => {
                            if (confirm('¿Eliminar esta ubicación?')) {
                                router.delete(
                                    `/vehicle-locations/${row.original.id}`,
                                    { preserveScroll: true },
                                );
                            }
                        }}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </Can>
            </div>
        ),
    },
];
