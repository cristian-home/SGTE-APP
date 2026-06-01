import { Badge } from '@/components/ui/badge';
import { formatEventTime } from '@/lib/datetime';

import type { ColumnDef } from '@tanstack/react-table';
import type { Service } from '@/types';

const currencyFormatter = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

function addMinutes(time: string, minutes: number): string {
    const [h, m] = time.split(':').map(Number);
    const total = h * 60 + m + minutes;
    const hh = Math.floor(total / 60) % 24;
    const mm = total % 60;
    return `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
}

function thirdPartyName(
    tp:
        | {
              company_name: string | null;
              first_name: string | null;
              first_lastname: string | null;
              is_natural_person: boolean;
          }
        | undefined,
): string {
    if (!tp) return '—';
    if (!tp.is_natural_person && tp.company_name) return tp.company_name;
    return [tp.first_name, tp.first_lastname].filter(Boolean).join(' ') || '—';
}

export const columns: ColumnDef<Service, unknown>[] = [
    {
        accessorKey: 'service_number',
        meta: { label: 'Consecutivo' },
        header: 'Consecutivo',
        cell: ({ row }) => (
            <span className="font-mono tabular-nums">
                {row.original.service_number}
            </span>
        ),
    },
    {
        id: 'plate',
        meta: { label: 'Placa' },
        header: 'Placa',
        cell: ({ row }) => {
            const vehicle = row.original.vehicle;
            return (
                <div className="flex items-center gap-1.5">
                    <span className="font-mono font-medium">
                        {vehicle?.plate ?? '—'}
                    </span>
                    {vehicle?.is_third_party && (
                        <Badge
                            variant="outline"
                            className="text-xs text-blue-600 dark:text-blue-400"
                        >
                            3ro
                        </Badge>
                    )}
                </div>
            );
        },
    },
    {
        id: 'driver_provider',
        meta: { label: 'Conductor/Proveedor' },
        header: 'Conductor/Proveedor',
        cell: ({ row }) => {
            const { vehicle, driver } = row.original;
            if (vehicle?.is_third_party) {
                return (
                    <div className="flex flex-col">
                        <span>{thirdPartyName(vehicle.third_party)}</span>
                        <span className="text-xs text-muted-foreground">
                            Proveedor
                        </span>
                    </div>
                );
            }
            if (driver) {
                return `${driver.first_name} ${driver.first_lastname}`;
            }
            return <span className="text-muted-foreground">—</span>;
        },
    },
    {
        id: 'schedule',
        meta: { label: 'Horario' },
        header: 'Horario',
        cell: ({ row }) => {
            const s = row.original;
            const plannedStart = formatEventTime(
                s.planned_start_at,
                s.timezone,
            );
            const plannedEnd = plannedStart
                ? addMinutes(plannedStart, s.planned_duration)
                : '';
            const actualStart = formatEventTime(s.actual_start_at, s.timezone);
            const actualEnd = formatEventTime(s.actual_end_at, s.timezone);
            return (
                <div className="flex flex-col">
                    <span>
                        {plannedStart} - {plannedEnd}
                    </span>
                    {actualStart && actualEnd && (
                        <span className="text-xs text-muted-foreground">
                            Real: {actualStart} - {actualEnd}
                        </span>
                    )}
                </div>
            );
        },
    },
    {
        id: 'client',
        meta: { label: 'Cliente' },
        header: 'Cliente',
        cell: ({ row }) => {
            const contract = row.original.contract;
            return (
                <div className="flex flex-col">
                    <span>{thirdPartyName(contract?.third_party)}</span>
                    {contract?.contract_number && (
                        <span className="text-xs text-muted-foreground">
                            {contract.contract_number}
                        </span>
                    )}
                </div>
            );
        },
    },
    {
        id: 'status',
        meta: { label: 'Estado' },
        header: 'Estado',
        cell: ({ row }) => {
            const status = row.original.service_status;
            return (
                <Badge
                    className={
                        status === 'closed'
                            ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                            : 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300'
                    }
                >
                    {status === 'closed' ? 'Cerrado' : 'Abierto'}
                </Badge>
            );
        },
    },
    {
        id: 'service_value',
        meta: { label: 'Valor del servicio' },
        header: () => (
            <span className="block text-right">Valor del servicio</span>
        ),
        cell: ({ row }) => {
            const value =
                Number(row.original.unit_value ?? 0) *
                Number(row.original.quantity ?? 0);
            if (!value) {
                return (
                    <span className="block text-right text-muted-foreground">
                        —
                    </span>
                );
            }
            return (
                <span className="block text-right font-medium tabular-nums">
                    {currencyFormatter.format(value)}
                </span>
            );
        },
    },
    {
        id: 'incidents',
        meta: { label: 'Novedades' },
        header: 'Novedades',
        cell: ({ row }) => {
            const count = row.original.service_incidents_count ?? 0;
            if (count > 0) {
                return (
                    <Badge
                        variant="outline"
                        className="border-yellow-400 text-yellow-600 dark:text-yellow-400"
                    >
                        {count} Nov
                    </Badge>
                );
            }
            return <span className="text-muted-foreground">—</span>;
        },
    },
    {
        id: 'billing_impact',
        meta: { label: 'Recargo novedades' },
        header: () => <span className="block text-right">Recargo nov.</span>,
        cell: ({ row }) => {
            const amount = Number(
                (
                    row.original as Service & {
                        billing_impact_amount?: string | number | null;
                    }
                ).billing_impact_amount ?? 0,
            );
            if (!amount) {
                return (
                    <span className="block text-right text-muted-foreground">
                        —
                    </span>
                );
            }
            return (
                <span className="block text-right font-medium text-amber-700 tabular-nums dark:text-amber-400">
                    {currencyFormatter.format(amount)}
                </span>
            );
        },
    },
    {
        id: 'total',
        meta: { label: 'Total' },
        header: () => <span className="block text-right">Total</span>,
        cell: ({ row }) => {
            const serviceValue =
                Number(row.original.unit_value ?? 0) *
                Number(row.original.quantity ?? 0);
            const impact = Number(
                (
                    row.original as Service & {
                        billing_impact_amount?: string | number | null;
                    }
                ).billing_impact_amount ?? 0,
            );
            const total = serviceValue + impact;
            if (!total) {
                return (
                    <span className="block text-right text-muted-foreground">
                        —
                    </span>
                );
            }
            return (
                <span className="block text-right font-semibold tabular-nums">
                    {currencyFormatter.format(total)}
                </span>
            );
        },
    },
];
