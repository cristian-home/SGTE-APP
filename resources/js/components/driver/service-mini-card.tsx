import { MapPin, Play, Truck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { ServiceStatusLabel } from '@/enums/ServiceStatus';
import { formatEventTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { Service } from '@/types';

function formatServiceTime(at: string | null, timezone: string): string {
    return formatEventTime(at, timezone) || '—';
}

function municipalityName(municipality?: { name: string } | null): string {
    return municipality?.name ?? '—';
}

function clientName(service: Service): string {
    const tp = service.contract?.third_party;
    if (!tp) {
        return 'Sin contrato';
    }
    return (
        tp.company_name ||
        `${tp.first_name ?? ''} ${tp.first_lastname ?? ''}`.trim() ||
        'Sin contrato'
    );
}

interface ServiceMiniCardProps {
    service: Service;
    onOpen: () => void;
    className?: string;
}

export function ServiceMiniCard({
    service,
    onOpen,
    className,
}: ServiceMiniCardProps) {
    const hasStarted = !!service.actual_start_at;
    const hasEnded = !!service.actual_end_at;
    const incidentCount = service.service_incidents_count ?? 0;
    const isClosed = service.service_status === 'closed';

    return (
        <Card
            role="button"
            tabIndex={0}
            data-dusk={`service-mini-${service.id}`}
            onClick={onOpen}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen();
                }
            }}
            className={cn(
                'cursor-pointer gap-1 p-3 text-xs transition-colors hover:bg-muted/40',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1.5 font-mono text-sm font-semibold">
                    <Truck className="size-3.5" />
                    {service.vehicle?.plate ?? '—'}
                    <span className="font-normal text-muted-foreground">
                        {service.service_number}
                    </span>
                </span>
                <div className="flex items-center gap-1.5">
                    {incidentCount > 0 && (
                        <Badge variant="destructive">
                            {incidentCount} novedad
                            {incidentCount > 1 ? 'es' : ''}
                        </Badge>
                    )}
                    <Badge variant={isClosed ? 'default' : 'secondary'}>
                        {ServiceStatusLabel[
                            service.service_status as keyof typeof ServiceStatusLabel
                        ] ?? service.service_status}
                    </Badge>
                </div>
            </div>
            <p className="truncate text-muted-foreground">
                {clientName(service)}
            </p>
            <div className="flex min-w-0 items-center gap-1.5">
                <MapPin className="size-3.5 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1 truncate">
                    <span className="capitalize">
                        {municipalityName(service.origin_municipality)}
                    </span>{' '}
                    &rarr;{' '}
                    <span className="capitalize">
                        {municipalityName(service.destination_municipality)}
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        &middot;{' '}
                        {formatServiceTime(
                            service.planned_start_at,
                            service.timezone,
                        )}{' '}
                        ({service.planned_duration} min)
                    </span>
                </span>
            </div>
            {hasStarted && (
                <div className="flex items-center gap-1.5">
                    <Play className="size-3.5 shrink-0 text-green-600" />
                    <span>
                        {formatServiceTime(
                            service.actual_start_at,
                            service.timezone,
                        )}
                        {hasEnded && (
                            <>
                                {' '}
                                &mdash;{' '}
                                {formatServiceTime(
                                    service.actual_end_at,
                                    service.timezone,
                                )}
                            </>
                        )}
                    </span>
                </div>
            )}
        </Card>
    );
}
