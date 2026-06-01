import { Link } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { HOUR_LABELS, serviceBarPosition } from '@/pages/gantt/gantt-utils';

export type DashboardTodayService = {
    id: number;
    service_number: string;
    vehicle_plate: string | null;
    planned_start_at: string | null;
    planned_duration_min: number | null;
    timezone: string;
    status: string | null;
    origin_label: string | null;
};

/**
 * Compact mini-Gantt of today's services for the dashboard. One row
 * per vehicle plate, bars positioned via the shared `serviceBarPosition`
 * helper so the layout stays consistent with the full /gantt view.
 * Bars are clickable → /services/{id}; header CTA → /gantt.
 */
export function TodayServicesGantt({
    services,
    className,
}: {
    services: DashboardTodayService[];
    className?: string;
}) {
    const byVehicle = new Map<string, DashboardTodayService[]>();
    for (const service of services) {
        const key = service.vehicle_plate ?? '— sin asignar';
        if (!byVehicle.has(key)) byVehicle.set(key, []);
        byVehicle.get(key)!.push(service);
    }
    const rows = Array.from(byVehicle.entries());

    return (
        <Card className={className}>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <CalendarRange
                            className="size-4 text-muted-foreground"
                            aria-hidden
                        />
                        Servicios de hoy
                    </CardTitle>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/gantt">Ver Planificador →</Link>
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        No hay servicios programados hoy.
                    </p>
                ) : (
                    <div className="@container/gantt space-y-2">
                        <GanttHourHeader />
                        {rows.map(([plate, plateServices]) => (
                            <GanttRow
                                key={plate}
                                plate={plate}
                                services={plateServices}
                            />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function GanttHourHeader() {
    return (
        <div className="flex items-center gap-2 text-[10px] text-muted-foreground tabular-nums">
            {/* Spacer mirrors the plate column in GanttRow so hour cells
                align with the track that lives at the same flex slot. */}
            <div className="w-18 shrink-0" />
            <div className="flex flex-1 items-center gap-0 border-b">
                {HOUR_LABELS.map((label, i) => {
                    // Progressive label density via the `@container/gantt`
                    // scope on the wrapper. The cells keep their flex-1
                    // distribution (the grid lines remain) — only the
                    // <span> text hides:
                    //   - i % 4 === 0  → 4-hour marks (05/09/13/17/21):
                    //                    always visible (≥5 labels at any width)
                    //   - i % 2 === 0  → 2-hour marks (07/11/15/19):
                    //                    shown from @md (~28rem track) up
                    //   - else         → hourly marks (06/08/10/…):
                    //                    shown from @lg (~32rem track) up
                    const labelVisibility =
                        i % 4 === 0
                            ? ''
                            : i % 2 === 0
                              ? 'hidden @lg/gantt:flex'
                              : 'hidden @3xl/gantt:flex';
                    return (
                        <div
                            className={`${labelVisibility} flex flex-1 justify-center border-l first:border-0`}
                            key={label}
                        >
                            <span>{label}</span>
                        </div>
                    );
                })}
            </div>
            {/* Invisible badge placeholder so the track widths match
                between header and rows. */}
            <Badge variant="outline" className="invisible shrink-0 text-[10px]">
                0
            </Badge>
        </div>
    );
}

function GanttRow({
    plate,
    services,
}: {
    plate: string;
    services: DashboardTodayService[];
}) {
    return (
        <div className="flex items-center gap-2">
            <div className="w-18 shrink-0 truncate font-mono text-xs font-medium">
                {plate}
            </div>
            <div className="relative h-7 flex-1 rounded bg-muted/40">
                {services.map((service) => {
                    if (
                        !service.planned_start_at ||
                        !service.planned_duration_min
                    ) {
                        return null;
                    }
                    const pos = serviceBarPosition(
                        service.planned_start_at,
                        service.planned_duration_min,
                        service.timezone,
                    );
                    if (pos === null) return null;
                    const isOpen = service.status === 'open';
                    return (
                        <Link
                            key={service.id}
                            href={`/services/${service.id}`}
                            className={cn(
                                'absolute inset-y-0.5 flex items-center overflow-hidden rounded px-1 text-[10px] font-medium transition-opacity hover:opacity-80',
                                isOpen
                                    ? 'bg-primary/80 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground',
                            )}
                            style={{
                                left: `${pos.left}%`,
                                width: `${pos.width}%`,
                            }}
                            title={`${service.service_number} · ${service.origin_label ?? ''}`}
                        >
                            <span className="truncate">
                                {service.origin_label}
                            </span>
                        </Link>
                    );
                })}
            </div>
            <Badge variant="outline" className="shrink-0 text-[10px]">
                {services.length}
            </Badge>
        </div>
    );
}
