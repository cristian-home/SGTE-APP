import { Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { DataTableColumnHeader } from '@/components/data-table';
import { FuecStatusPill } from '@/components/fuecs/fuec-status-pill';
import { Button } from '@/components/ui/button';

import type { ColumnDef } from '@tanstack/react-table';

export interface FuecRow {
    id: number;
    uuid: string;
    consecutive_number: number;
    generated_at: string | null;
    status: string;
    service?: {
        id: number;
        service_number: string;
        service_date: string | null;
        vehicle?: { id: number; plate: string } | null;
        driver?: {
            id: number;
            first_name: string | null;
            first_lastname: string | null;
        } | null;
        contract?: { id: number; contract_number: string } | null;
    } | null;
}

const dateFormatter = new Intl.DateTimeFormat('es-CO', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

function driverName(
    d: FuecRow['service'] extends infer T
        ? T extends { driver?: infer D }
            ? D
            : null
        : null,
): string {
    if (!d) return '—';
    const name = [
        (d as { first_name?: string | null }).first_name,
        (d as { first_lastname?: string | null }).first_lastname,
    ]
        .filter(Boolean)
        .join(' ')
        .trim();
    return name !== '' ? name : '—';
}

export const fuecColumns: ColumnDef<FuecRow, unknown>[] = [
    {
        accessorKey: 'consecutive_number',
        meta: { label: 'Consecutivo' },
        header: ({ column }) => (
            <DataTableColumnHeader column={column} title="Consecutivo" />
        ),
        cell: ({ row }) => (
            <Link
                href={`/fuecs/${row.original.id}`}
                className="font-mono text-primary hover:underline"
            >
                {row.original.consecutive_number}
            </Link>
        ),
    },
    {
        id: 'servicio',
        meta: { label: 'Servicio' },
        header: 'Servicio',
        cell: ({ row }) => {
            const service = row.original.service;
            if (!service)
                return <span className="text-muted-foreground">—</span>;
            const date = service.service_date
                ? dateFormatter.format(new Date(service.service_date))
                : '—';
            return (
                <Link
                    href={`/services/${service.id}`}
                    className="text-primary hover:underline"
                >
                    <span className="font-mono">{service.service_number}</span>
                    <span className="ml-1 text-xs text-muted-foreground">
                        {date}
                    </span>
                </Link>
            );
        },
    },
    {
        id: 'vehiculo',
        meta: { label: 'Vehículo' },
        header: 'Vehículo',
        cell: ({ row }) => (
            <span className="font-mono">
                {row.original.service?.vehicle?.plate ?? '—'}
            </span>
        ),
    },
    {
        id: 'conductor',
        meta: { label: 'Conductor' },
        header: 'Conductor',
        cell: ({ row }) => (
            <span>{driverName(row.original.service?.driver ?? null)}</span>
        ),
    },
    {
        id: 'estado',
        meta: { label: 'Estado' },
        header: 'Estado',
        cell: ({ row }) => <FuecStatusPill status={row.original.status} />,
    },
    {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => (
            <Button
                asChild
                variant="ghost"
                size="icon"
                aria-label="Ver detalles"
            >
                <Link href={`/fuecs/${row.original.id}`}>
                    <Eye className="size-4" />
                </Link>
            </Button>
        ),
    },
];
