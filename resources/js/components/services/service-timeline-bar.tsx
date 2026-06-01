import { Badge } from '@/components/ui/badge';
import { formatEventTime } from '@/lib/datetime';

interface ServiceTimelineBarProps {
    /** UTC instant (ISO 8601) of the planned start. */
    plannedStartAt: string;
    plannedDuration: number;
    /** UTC instant (ISO 8601) of the actual start, null when not started yet. */
    actualStartAt: string | null;
    /** UTC instant (ISO 8601) of the actual end, null when not finished yet. */
    actualEndAt: string | null;
    /** IANA timezone the service is operationally anchored in. */
    timezone: string;
}

/**
 * Whole minutes between two UTC instants. Positioning the bars by absolute
 * elapsed time (rather than minutes-of-day) keeps a window that crosses
 * midnight contiguous — a minutes-of-day value wraps at 00:00 and would make
 * the actual end land *before* its start.
 */
function minutesBetween(fromAt: string, toAt: string): number {
    return (Date.parse(toAt) - Date.parse(fromAt)) / 60000;
}

export function ServiceTimelineBar({
    plannedStartAt,
    plannedDuration,
    actualStartAt,
    actualEndAt,
    timezone,
}: ServiceTimelineBarProps) {
    // Anchor every offset to the planned start instant (= 0). The axis is
    // self-relative, so the absolute origin is irrelevant for layout.
    const plannedStart = 0;
    const plannedEnd = plannedDuration;

    const hasActual = actualStartAt !== null && actualEndAt !== null;
    const actualStart = hasActual
        ? minutesBetween(plannedStartAt, actualStartAt)
        : null;
    const actualEnd = hasActual
        ? minutesBetween(plannedStartAt, actualEndAt)
        : null;

    const actualDuration =
        actualStart !== null && actualEnd !== null
            ? actualEnd - actualStart
            : null;

    const axisMin = Math.min(plannedStart, actualStart ?? plannedStart) - 30;
    const axisMax = Math.max(plannedEnd, actualEnd ?? plannedEnd) + 30;
    const axisRange = axisMax - axisMin;

    const pctLeft = (val: number) => `${((val - axisMin) / axisRange) * 100}%`;
    const pctWidth = (start: number, end: number) =>
        `${((end - start) / axisRange) * 100}%`;

    return (
        <div className="space-y-3">
            {/* Planned bar */}
            <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>Planificado</span>
                    <Badge variant="secondary" className="text-[10px]">
                        {plannedDuration} min
                    </Badge>
                </div>
                <div className="relative h-5 w-full rounded bg-muted/50">
                    <div
                        className="absolute top-0 flex h-full items-center rounded bg-blue-500/70"
                        style={{
                            left: pctLeft(plannedStart),
                            width: pctWidth(plannedStart, plannedEnd),
                        }}
                    >
                        <span className="truncate px-1.5 text-[10px] font-medium text-white">
                            {formatEventTime(plannedStartAt, timezone)}
                        </span>
                    </div>
                </div>
            </div>

            {/* Actual bar */}
            <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <span>Real</span>
                    {actualDuration !== null && actualDuration > 0 && (
                        <Badge variant="secondary" className="text-[10px]">
                            {actualDuration} min
                        </Badge>
                    )}
                </div>
                <div className="relative h-5 w-full rounded bg-muted/50">
                    {hasActual && actualStart !== null && actualEnd !== null ? (
                        <div
                            className="absolute top-0 flex h-full items-center rounded bg-emerald-500/70"
                            style={{
                                left: pctLeft(actualStart),
                                width: pctWidth(actualStart, actualEnd),
                            }}
                        >
                            <span className="truncate px-1.5 text-[10px] font-medium text-white">
                                {formatEventTime(actualStartAt, timezone)}
                            </span>
                        </div>
                    ) : (
                        <div
                            className="absolute top-0 flex h-full items-center rounded border border-dashed border-muted-foreground/30"
                            style={{
                                left: pctLeft(plannedStart),
                                width: pctWidth(plannedStart, plannedEnd),
                            }}
                        >
                            <span className="px-1.5 text-[10px] text-muted-foreground">
                                Sin datos
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {/* Legend */}
            <div className="flex gap-4 text-[10px] text-muted-foreground">
                <div className="flex items-center gap-1">
                    <div className="size-2 rounded-full bg-blue-500/70" />
                    <span>Planificado</span>
                </div>
                <div className="flex items-center gap-1">
                    <div className="size-2 rounded-full bg-emerald-500/70" />
                    <span>Real</span>
                </div>
            </div>
        </div>
    );
}
