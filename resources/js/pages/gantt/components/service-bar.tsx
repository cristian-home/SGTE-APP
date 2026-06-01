import { memo } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatEventTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { Service } from '@/types/models';

interface ServiceBarProps {
    service: Service;
    /**
     * Position on the timeline. `unit` chooses how `left` and `width`
     * are interpreted — `%` for the single-day mini-Gantt (dashboard)
     * and `px` for the continuous multi-day timeline (/gantt). Object
     * identity matters: the parent must keep `position` stable across
     * renders (via useMemo) for the surrounding `memo` wrapper to be
     * able to skip re-renders.
     */
    position: { left: number; width: number; unit?: '%' | 'px' };
    onClick: (serviceId: number) => void;
}

function getClientName(service: Service): string {
    const tp = service.contract?.third_party;
    if (!tp) return 'Servicio';
    if (tp.is_natural_person) {
        return [tp.first_name, tp.first_lastname].filter(Boolean).join(' ');
    }
    return tp.company_name ?? 'Servicio';
}

function getDriverName(service: Service): string {
    if (!service.driver) return '3ro';
    return [service.driver.first_name, service.driver.first_lastname]
        .filter(Boolean)
        .join(' ');
}

function formatServiceTime(at: string | null, timezone: string): string {
    return formatEventTime(at, timezone) || '--:--';
}

/**
 * Assumes a TooltipProvider is mounted somewhere up the tree. The Gantt
 * page mounts a single provider in HourlyGrid so we don't pay for 50+
 * provider instances during resize / scroll re-renders.
 */
function ServiceBar({ service, position, onClick }: ServiceBarProps) {
    const isOpen = service.service_status === 'open';
    const isDeclined = !!service.driver_declined_at;
    const isBlocked = service.blocked === true;
    const blockedReasons = service.blocked_reasons ?? [];

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    data-service-blocked={isBlocked ? 'true' : 'false'}
                    className={cn(
                        'absolute inset-y-0.5 cursor-pointer overflow-hidden rounded px-1.5 py-0.5 text-left text-white shadow-sm transition-colors',
                        isBlocked
                            ? 'bg-zinc-400 opacity-70 ring-2 ring-zinc-500 hover:bg-zinc-500 dark:bg-zinc-600 dark:ring-zinc-400 dark:hover:bg-zinc-500'
                            : isDeclined
                              ? 'bg-red-500 ring-2 ring-red-300 hover:bg-red-600 dark:bg-red-600 dark:hover:bg-red-700'
                              : isOpen
                                ? 'bg-orange-400 hover:bg-orange-500 dark:bg-orange-500 dark:hover:bg-orange-600'
                                : 'bg-green-500 hover:bg-green-600 dark:bg-green-600 dark:hover:bg-green-700',
                    )}
                    style={{
                        left: `${position.left}${position.unit ?? '%'}`,
                        width: `${position.width}${position.unit ?? '%'}`,
                        minWidth: '24px',
                    }}
                    onClick={(e) => {
                        e.stopPropagation();
                        onClick(service.id);
                    }}
                >
                    {isBlocked && (
                        <span className="absolute top-0 right-0 rounded-bl bg-white/40 px-1 text-[9px] leading-none font-semibold uppercase">
                            Bloqueado
                        </span>
                    )}
                    {!isBlocked && isDeclined && (
                        <span className="absolute top-0 right-0 rounded-bl bg-white/30 px-1 text-[9px] leading-none font-semibold uppercase">
                            Declinado
                        </span>
                    )}
                    <div className="truncate text-[10px] leading-tight font-medium">
                        {getClientName(service)}
                    </div>
                    <div className="truncate text-[9px] leading-tight opacity-80">
                        {getDriverName(service)}
                    </div>
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs">
                <div className="space-y-1 text-xs">
                    <p className="font-mono font-semibold tabular-nums">
                        {service.service_number}
                    </p>
                    <p className="font-medium">{getClientName(service)}</p>
                    <p>Conductor: {getDriverName(service)}</p>
                    <p>
                        Planificado:{' '}
                        {formatServiceTime(
                            service.planned_start_at,
                            service.timezone,
                        )}{' '}
                        ({service.planned_duration} min)
                    </p>
                    {service.actual_start_at && (
                        <p>
                            Real:{' '}
                            {formatServiceTime(
                                service.actual_start_at,
                                service.timezone,
                            )}
                            {service.actual_end_at
                                ? ` - ${formatServiceTime(service.actual_end_at, service.timezone)}`
                                : ''}
                        </p>
                    )}
                    <p>Estado: {isOpen ? 'Abierto' : 'Cerrado'}</p>
                    {isBlocked && (
                        <div className="space-y-0.5 border-t border-primary-foreground/20 pt-1">
                            <p className="font-semibold">
                                Documentos vencidos:
                            </p>
                            {blockedReasons.map((reason, idx) => (
                                <p key={idx}>· {reason}</p>
                            ))}
                        </div>
                    )}
                    {isDeclined && (
                        <p className="border-t border-primary-foreground/20 pt-1 font-semibold">
                            Declinado por el conductor — pendiente de
                            reasignación
                        </p>
                    )}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

export default memo(ServiceBar);
