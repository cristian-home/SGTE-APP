import { Lock } from 'lucide-react';
import { memo } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { DayStatus } from '@/types/models';
import type { Ymd } from '../utils/coordinates';

interface Props {
    date: Ymd;
    /** Highlight when this segment represents "today" in operation TZ. */
    isToday?: boolean;
    /**
     * Operational day-status row for this date. When present, renders
     * an inline badge ("Ejecutado" / "Proyectado") next to the day
     * label so the badge scrolls with the day instead of lying about
     * which day it describes.
     */
    dayStatus?: DayStatus | null;
    /**
     * Width of the sticky Vehículo sidebar in px. Used as the left
     * offset of the sticky label so the label pins right at the
     * sidebar's right edge as the user scrolls through the day's
     * hours. The browser clamps the sticky element to the parent
     * day-column's bounds, so the label gracefully hands off to the
     * next day's label at the column boundary.
     */
    sidebarPx: number;
}

const dayFormatter = new Intl.DateTimeFormat('es-CO', {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
});

/**
 * Day label that "sticks" to the sidebar's right edge while the day
 * is in view, then hands off to the next day's label at the column
 * boundary. Achieved with `position: sticky; left: sidebarPx` inside
 * each day's fixed-width column — the column wrapper (rendered by
 * HourlyGrid) supplies the muted background strip and the today
 * highlight; this component is just the sticky content pill.
 */
function DaySeparator({
    date,
    isToday = false,
    dayStatus = null,
    sidebarPx,
}: Props) {
    const [y, m, d] = date.split('-').map(Number);
    // Build a stable noon-UTC date so the formatter never picks the
    // wrong calendar day across DST boundaries.
    const label = dayFormatter.format(new Date(Date.UTC(y, m - 1, d, 12)));
    const isExecuted = dayStatus?.status === 'executed';

    return (
        <div
            className={cn(
                'sticky top-0 z-20 flex h-6 w-max items-center gap-2 px-2 text-xs font-medium',
                isToday ? 'text-primary' : 'text-muted-foreground',
            )}
            style={{ left: sidebarPx }}
        >
            <span className="truncate capitalize">{label}</span>
            {dayStatus && (
                <Badge
                    className={cn(
                        'h-4 px-1.5 text-[10px] font-medium',
                        isExecuted
                            ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                            : 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300',
                    )}
                >
                    {isExecuted ? 'Ejecutado' : 'Proyectado'}
                </Badge>
            )}
            {isExecuted && (
                <Badge
                    title="No se pueden crear nuevos servicios en este día"
                    className="h-4 gap-1 border border-green-200 bg-transparent px-1.5 text-[10px] font-normal whitespace-nowrap text-green-700 dark:border-green-800 dark:text-green-300"
                >
                    <Lock className="size-2.5" aria-hidden />
                    Bloqueado para nuevos servicios
                </Badge>
            )}
        </div>
    );
}

export default memo(DaySeparator);
