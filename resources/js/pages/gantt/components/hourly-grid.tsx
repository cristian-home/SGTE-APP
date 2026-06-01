import { router } from '@inertiajs/react';
import { useVirtualizer } from '@tanstack/react-virtual';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    create as servicesCreate,
    edit as servicesEdit,
} from '@/actions/App/Http/Controllers/ServiceController';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { computeVehicleDocStatus, HOUR_LABELS } from '../gantt-utils';
import {
    scrollLeftForDateCenter,
    scrollLeftForInstantCenter,
    useGanttScroll,
} from '../hooks/use-gantt-scroll';
import {
    addDays,
    dayOffset,
    instantToPxFromEpoch,
    pixelToDateTime,
    PX_PER_DAY,
    PX_PER_HOUR,
    serviceBarAbsolutePosition,
    type Ymd,
} from '../utils/coordinates';
import DaySeparator from './day-separator';
import GanttFetchingBar from './gantt-fetching-bar';
import NowIndicator from './now-indicator';
import ServiceBar from './service-bar';
import VehicleSidebarItem from './vehicle-sidebar-item';

import type { Service, Vehicle } from '@/types/models';
import type { DayCache } from '../hooks/use-gantt-days';

interface InitialDay {
    date: Ymd;
    services: Service[];
}

interface HourlyGridProps {
    vehicles: Vehicle[];
    initialDay: InitialDay;
    /**
     * Anchor date for the continuous timeline; pixel 0 = 00:00 of
     * `epoch` in operation TZ. Owned by the page so the URL / Hoy /
     * date-picker can drive it; updated INTERNALLY here too when the
     * user scrolls into an edge buffer zone (seamless sliding window).
     */
    epoch: Ymd;
    /**
     * Page-level setter so the grid can shift the window itself when
     * the user approaches an edge. Pure state update — no lock, no
     * dim, no fetch coupling. The grid uses a useLayoutEffect to
     * compensate `scrollLeft` synchronously after each shift so the
     * visible content never jumps.
     */
    onEpochChange: (next: Ymd | ((prev: Ymd) => Ymd)) => void;
    operationTz: string;
    canCreateServices: boolean;
    /**
     * Per-day cache owned by the page (via useGanttDays). The grid
     * reads services + dayStatus from it; service creation is gated
     * per-day at click time using `dayStatus.status === 'executed'`.
     */
    cache: DayCache;
    ensureDay: (date: Ymd) => void;
    isFetching: boolean;
    /** Total days the virtualizer can show (centered roughly on hoy). */
    numDays: number;
    /** Called when the centered day changes (for URL sync). */
    onCenterDateChange: (date: Ymd) => void;
    /**
     * Exposes scroll-driving handles back to the page so the header
     * controls can drive horizontal scroll. `jumpToDate(date)` smooth-
     * scrolls so the date lands centered (on noon); `jumpToNow()`
     * smooth-scrolls so the current instant lands centered on the Now
     * indicator's vertical red line.
     */
    onMount?: (handles: {
        jumpToDate: (date: Ymd) => void;
        jumpToNow: () => void;
    }) => void;
    /**
     * When non-null, the grid scrolls so this target lands at the
     * horizontal center of the viewport — using the CURRENT value of
     * `epoch` and the live `scroller.clientWidth`. The page sets this
     * together with a new epoch when the user picks a target outside
     * the current window (out-of-range jump); useLayoutEffect handles
     * the scroll adjustment before paint so the swap is invisible.
     *
     * `mode = 'noon'`: centers on noon of `date` (date-picker default).
     * `mode = 'now'`: centers on the current instant within `date`
     *                 (Hoy button — aligns viewport with the Now line).
     */
    recenterTo?: { date: Ymd; mode: 'noon' | 'now' } | null;
}

const SIDEBAR_PX_MOBILE = 96; // w-24 — plate + 2 abbreviated badges
const SIDEBAR_PX_DESKTOP = 112; // w-28 — original, fits full badge text
const SIDEBAR_BREAKPOINT_PX = 640; // Tailwind `sm`
const ROW_HEIGHT_PX = 36; // matches existing minHeight
const HEADER_HEIGHT_PX = 48; // day banner + hour labels strip
// Scroll-position buffer around the canvas edges that triggers a
// single-day slide. One day's worth means the user can't see "past
// the end" of the window before the next day silently shifts in.
const EDGE_SLIDE_BUFFER_PX = PX_PER_DAY;

/**
 * Sidebar width follows the Tailwind `sm` breakpoint: narrower on
 * mobile so the timeline reclaims ~30px of viewport, full width on
 * tablet+ where horizontal real estate is plentiful. SSR-safe — picks
 * the desktop value first, then upgrades after hydration if the
 * viewport reports otherwise.
 */
function useSidebarWidthPx(): number {
    const [px, setPx] = useState<number>(SIDEBAR_PX_DESKTOP);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const mq = window.matchMedia(`(min-width: ${SIDEBAR_BREAKPOINT_PX}px)`);
        const update = () =>
            setPx(mq.matches ? SIDEBAR_PX_DESKTOP : SIDEBAR_PX_MOBILE);
        update();
        mq.addEventListener('change', update);
        return () => mq.removeEventListener('change', update);
    }, []);

    return px;
}

/**
 * Pre-baked grid-line background: 23 dim borders per day + 1 brighter
 * midnight. Lines sit at the START of each block (positions 0, 38, 76…
 * for hours, 0, 912, 1824… for days) so they align pixel-perfect with
 * the header cells, which use `border-l` (border on the LEFT of each
 * cell). Putting them at the end would offset everything by 1px.
 */
const GRID_BG_STYLE: React.CSSProperties = {
    backgroundImage: `repeating-linear-gradient(
        to right,
        rgba(120, 120, 120, 0.18) 0 1px,
        transparent 1px ${PX_PER_HOUR}px
    ), repeating-linear-gradient(
        to right,
        rgba(120, 120, 120, 0.45) 0 1px,
        transparent 1px ${PX_PER_DAY}px
    )`,
    backgroundSize: `${PX_PER_HOUR}px 100%, ${PX_PER_DAY}px 100%`,
};

export default function HourlyGrid({
    vehicles,
    initialDay,
    epoch,
    onEpochChange,
    operationTz,
    canCreateServices,
    cache,
    ensureDay,
    isFetching,
    numDays,
    onCenterDateChange,
    onMount,
    recenterTo = null,
}: HourlyGridProps) {
    'use no memo';
    // TanStack Virtual's useVirtualizer returns non-memoizable functions,
    // so opt this component out of the React Compiler (same convention as
    // the useServerTable pages). Silences react-hooks/incompatible-library.

    const scrollerRef = useRef<HTMLDivElement | null>(null);
    const sidebarPx = useSidebarWidthPx();
    // Mirror in a ref so stable callbacks read the latest value without
    // re-binding on every breakpoint change.
    const sidebarPxRef = useRef(sidebarPx);
    useEffect(() => {
        sidebarPxRef.current = sidebarPx;
    }, [sidebarPx]);

    const today = useMemo(
        () =>
            new Intl.DateTimeFormat('en-CA', { timeZone: operationTz }).format(
                new Date(),
            ),
        [operationTz],
    );

    const vehicleStatuses = useMemo(() => {
        const map: Record<
            number,
            { isBlocked: boolean; hasWarning: boolean; expiredDocs: string[] }
        > = {};
        for (const v of vehicles) {
            map[v.id] = computeVehicleDocStatus(v, today);
        }
        return map;
    }, [vehicles, today]);

    const getScrollElement = useCallback(() => scrollerRef.current, []);
    const estimateSize = useCallback(() => PX_PER_DAY, []);

    // eslint-disable-next-line react-hooks/incompatible-library
    const virtualizer = useVirtualizer({
        horizontal: true,
        count: numDays,
        getScrollElement,
        estimateSize,
        overscan: 1,
    });

    const visibleDays = virtualizer.getVirtualItems();

    // Trigger fetches for visible (+ overscan) days. ensureDay is
    // idempotent — second-and-later calls for the same date are no-ops.
    useEffect(() => {
        for (const item of visibleDays) {
            ensureDay(addDays(epoch, item.index));
        }
    }, [visibleDays, epoch, ensureDay]);

    // Index services by date+vehicle for O(1) lookup inside each row.
    const servicesByDateVehicle = useMemo(() => {
        const map = new Map<Ymd, Map<number, Service[]>>();
        for (const [date, entry] of cache.entries()) {
            if (entry.status !== 'ready') continue;
            const byVehicle = new Map<number, Service[]>();
            for (const s of entry.services) {
                const list = byVehicle.get(s.vehicle_id) ?? [];
                list.push(s);
                byVehicle.set(s.vehicle_id, list);
            }
            map.set(date, byVehicle);
        }
        return map;
    }, [cache]);

    // Pre-compute absolute pixel positions per service id. Memoizing
    // by (cache, epoch) keeps the position object identities stable
    // across re-renders so the memo() wrapper around ServiceBar can
    // skip work during resize / scroll-driven parent re-renders.
    const positionsByServiceId = useMemo(() => {
        const map = new Map<
            number,
            { left: number; width: number; unit: 'px' }
        >();
        for (const entry of cache.values()) {
            if (entry.status !== 'ready') continue;
            for (const s of entry.services) {
                const pos = serviceBarAbsolutePosition(
                    s.planned_start_at,
                    s.planned_duration,
                    s.timezone,
                    epoch,
                );
                if (pos)
                    map.set(s.id, {
                        left: pos.left,
                        width: pos.width,
                        unit: 'px',
                    });
            }
        }
        return map;
    }, [cache, epoch]);

    // URL sync via debounced scroll — independent of the sliding
    // mechanism (sliding compensates scrollLeft synchronously before
    // paint, so the centered-date computation downstream is always
    // self-consistent and never reports a bogus value).
    useGanttScroll({
        scrollerRef,
        epoch,
        onCenterDateChange,
        sidebarPx,
        debounceMs: 400,
    });

    // ─── Seamless edge sliding ─────────────────────────────────────
    //
    // When the user scrolls within `EDGE_SLIDE_BUFFER_PX` (one day) of
    // either edge, shift `epoch` by N days and compensate `scrollLeft`
    // sync in a useLayoutEffect so the visible content stays put.
    // The browser's momentum scroll keeps going; the user sees more
    // days appear seamlessly without any pause, lock, or dim.
    //
    // Math: if epoch moves +K days, every existing day's
    // canvasPosition drops by K × PX_PER_DAY. To keep the viewport
    // showing the same content, scrollLeft must drop by the same.
    // `pendingScrollAdjustRef` accumulates the compensation across
    // multiple slide() calls before a single commit, then the
    // useLayoutEffect drains it in one DOM write.

    const pendingScrollAdjustRef = useRef(0);
    const recenterToRef = useRef(recenterTo);
    useEffect(() => {
        recenterToRef.current = recenterTo;
    }, [recenterTo]);

    const slide = useCallback(
        (deltaDays: number) => {
            if (deltaDays === 0) return;
            pendingScrollAdjustRef.current -= deltaDays * PX_PER_DAY;
            onEpochChange((prev) => addDays(prev, deltaDays));
        },
        [onEpochChange],
    );

    useEffect(() => {
        const scroller = scrollerRef.current;
        if (!scroller) return;

        function onScroll() {
            if (!scroller) return;
            // Pause sliding while the page is performing a re-center
            // (out-of-range date picker jump) — that flow owns the
            // scroll position until React commits.
            if (recenterToRef.current) return;

            const { scrollLeft, scrollWidth, clientWidth } = scroller;
            const distFromLeft = scrollLeft;
            const distFromRight = scrollWidth - scrollLeft - clientWidth;

            if (distFromLeft < EDGE_SLIDE_BUFFER_PX) {
                // How many full PX_PER_DAY units the user is past the
                // trigger. Ceil so any incursion always slides ≥1 day;
                // a fast flick that lands past the absolute edge
                // (distFromLeft ≈ 0) gets a 1-day shift per scroll
                // event, which the momentum naturally continues.
                const daysOver = Math.max(
                    1,
                    Math.ceil(
                        (EDGE_SLIDE_BUFFER_PX - distFromLeft) / PX_PER_DAY,
                    ),
                );
                slide(-daysOver);
            } else if (distFromRight < EDGE_SLIDE_BUFFER_PX) {
                const daysOver = Math.max(
                    1,
                    Math.ceil(
                        (EDGE_SLIDE_BUFFER_PX - distFromRight) / PX_PER_DAY,
                    ),
                );
                slide(daysOver);
            }
        }

        scroller.addEventListener('scroll', onScroll, { passive: true });
        return () => {
            scroller.removeEventListener('scroll', onScroll);
        };
    }, [slide]);

    // Compensation: drain pendingScrollAdjustRef after each epoch
    // commit (set by slide() above). Runs synchronously before paint
    // so the user never sees an intermediate frame.
    useLayoutEffect(() => {
        if (pendingScrollAdjustRef.current === 0) return;
        const scroller = scrollerRef.current;
        if (!scroller) return;
        scroller.scrollLeft += pendingScrollAdjustRef.current;
        pendingScrollAdjustRef.current = 0;
    }, [epoch]);

    // External re-center: page-driven jump to a target outside the
    // current window. Page sets `recenterTo` alongside `setEpoch`;
    // this effect centers the viewport on that target after commit.
    useLayoutEffect(() => {
        if (!recenterTo) return;
        const scroller = scrollerRef.current;
        if (!scroller) return;
        const timelineWidth = scroller.clientWidth - sidebarPxRef.current;
        let centerPx: number;
        if (recenterTo.mode === 'now') {
            // Center on the current instant — visible Now line lands
            // at the viewport's horizontal middle.
            centerPx = instantToPxFromEpoch(
                new Date().toISOString(),
                operationTz,
                epoch,
            );
        } else {
            // Center on noon of the date.
            const offset = dayOffset(recenterTo.date, epoch);
            centerPx = (offset + 0.5) * PX_PER_DAY;
        }
        scroller.scrollLeft = Math.max(
            0,
            Math.round(centerPx - timelineWidth / 2),
        );
    }, [recenterTo, epoch, operationTz]);

    // Expose jumpToDate / jumpToNow to the page so the header controls
    // can drive horizontal scroll. Smooth scrolls for in-range targets;
    // the page handles out-of-range via setEpoch + recenterTo
    // separately (instant snap).
    const epochRef = useRef(epoch);
    useEffect(() => {
        epochRef.current = epoch;
    }, [epoch]);
    const operationTzRef = useRef(operationTz);
    useEffect(() => {
        operationTzRef.current = operationTz;
    }, [operationTz]);
    const jumpToDate = useCallback((date: Ymd) => {
        const scroller = scrollerRef.current;
        if (!scroller) return;
        const left = scrollLeftForDateCenter(
            date,
            epochRef.current,
            scroller.clientWidth - sidebarPxRef.current,
        );
        scroller.scrollTo({ left, behavior: 'smooth' });
    }, []);
    const jumpToNow = useCallback(() => {
        const scroller = scrollerRef.current;
        if (!scroller) return;
        const left = scrollLeftForInstantCenter(
            new Date().toISOString(),
            operationTzRef.current,
            epochRef.current,
            scroller.clientWidth - sidebarPxRef.current,
        );
        scroller.scrollTo({ left, behavior: 'smooth' });
    }, []);

    const onMountRef = useRef(onMount);
    useEffect(() => {
        onMountRef.current = onMount;
    }, [onMount]);
    const initialDayRef = useRef(initialDay);
    useEffect(() => {
        initialDayRef.current = initialDay;
    }, [initialDay]);

    // Initial scroll-anchor: when the scroller is first mounted, center
    // on the initial day.
    const didInitialScrollRef = useRef(false);
    const setScroller = useCallback(
        (node: HTMLDivElement | null) => {
            scrollerRef.current = node;
            if (node && !didInitialScrollRef.current) {
                didInitialScrollRef.current = true;
                queueMicrotask(() => {
                    const left = scrollLeftForDateCenter(
                        initialDayRef.current.date,
                        epochRef.current,
                        node.clientWidth - sidebarPxRef.current,
                    );
                    node.scrollLeft = left;
                });
                onMountRef.current?.({ jumpToDate, jumpToNow });
            }
        },
        [jumpToDate, jumpToNow],
    );

    // Stable handler refs so memo()'d ServiceBar / VehicleSidebarItem
    // don't bust their shallow-prop comparison on every parent render.
    const handleServiceClick = useCallback((serviceId: number) => {
        router.get(servicesEdit(serviceId).url);
    }, []);

    const handleEmptyCellClick = useCallback(
        (vehicle: Vehicle, e: React.MouseEvent<HTMLDivElement>) => {
            const status = vehicleStatuses[vehicle.id];
            if (status?.isBlocked || !canCreateServices) return;
            const target = e.currentTarget;
            const rect = target.getBoundingClientRect();
            const absPx = e.clientX - rect.left;
            const { date, timeHHMM } = pixelToDateTime(absPx, epoch);
            if (cache.get(date)?.dayStatus?.status === 'executed') return;
            router.get(servicesCreate().url, {
                vehicle_id: vehicle.id,
                planned_start_time: timeHHMM,
                service_date: date,
            });
        },
        [vehicleStatuses, canCreateServices, epoch, cache],
    );

    const totalTimelineWidth = numDays * PX_PER_DAY;

    return (
        // Single TooltipProvider for the whole grid so we don't pay
        // for 50+ provider instances and tooltip delay stays unified.
        <TooltipProvider delayDuration={300}>
            <div className="relative size-full">
                <GanttFetchingBar isFetching={isFetching} />
                <div ref={setScroller} className="size-full overflow-auto">
                    <div
                        className="relative"
                        style={{ width: sidebarPx + totalTimelineWidth }}
                    >
                        {/* Header strip: sticky top. Sidebar corner z-30, timeline header z-20. */}
                        <div
                            className="sticky top-0 z-20 flex border-b bg-muted/50"
                            style={{ height: HEADER_HEIGHT_PX }}
                        >
                            <div
                                className="sticky left-0 z-30 flex shrink-0 items-center border-r bg-background px-2"
                                style={{ width: sidebarPx }}
                            >
                                <span className="text-xs font-medium text-muted-foreground">
                                    Vehículo
                                </span>
                            </div>
                            <div
                                className="relative"
                                style={{ width: totalTimelineWidth }}
                            >
                                {visibleDays.map((item) => {
                                    const date = addDays(epoch, item.index);
                                    const isToday = date === today;
                                    return (
                                        // key by date so the same day reuses
                                        // its DOM node when its virtual index
                                        // shifts during a slide.
                                        <div
                                            key={date}
                                            className="absolute inset-y-0"
                                            style={{
                                                left: item.start,
                                                width: PX_PER_DAY,
                                            }}
                                        >
                                            <div
                                                className={cn(
                                                    'absolute inset-x-0 top-0 h-6 border-x border-border',
                                                    isToday
                                                        ? 'bg-primary/10'
                                                        : 'bg-muted/80',
                                                )}
                                            />
                                            <DaySeparator
                                                date={date}
                                                isToday={isToday}
                                                dayStatus={
                                                    cache.get(date)
                                                        ?.dayStatus ?? null
                                                }
                                                sidebarPx={sidebarPx}
                                            />
                                            <div
                                                className="absolute inset-x-0 bottom-0 flex"
                                                style={{ top: 24 }}
                                            >
                                                {HOUR_LABELS.map((label) => (
                                                    <div
                                                        key={label}
                                                        className="flex-1 border-l border-border/50 p-1 text-center text-[10px] text-muted-foreground"
                                                    >
                                                        {label}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Body rows. */}
                        {vehicles.map((vehicle) => {
                            const status = vehicleStatuses[vehicle.id];
                            const clickable =
                                canCreateServices && !status?.isBlocked;
                            return (
                                <div
                                    key={vehicle.id}
                                    className={cn(
                                        'flex border-b',
                                        status?.isBlocked &&
                                            'bg-neutral-100 dark:bg-neutral-800/50',
                                    )}
                                    style={{
                                        contentVisibility: 'auto',
                                        containIntrinsicSize: `${sidebarPx + totalTimelineWidth}px ${ROW_HEIGHT_PX}px`,
                                    }}
                                >
                                    <div
                                        className="sticky left-0 z-10 flex shrink-0 items-center border-r bg-background"
                                        style={{
                                            width: sidebarPx,
                                            height: ROW_HEIGHT_PX,
                                        }}
                                    >
                                        <VehicleSidebarItem
                                            vehicle={vehicle}
                                            isBlocked={
                                                status?.isBlocked ?? false
                                            }
                                            hasWarning={
                                                status?.hasWarning ?? false
                                            }
                                            expiredDocs={
                                                status?.expiredDocs ?? []
                                            }
                                        />
                                    </div>
                                    <div
                                        className={cn(
                                            'relative',
                                            clickable
                                                ? 'cursor-cell'
                                                : 'cursor-default',
                                        )}
                                        style={{
                                            width: totalTimelineWidth,
                                            height: ROW_HEIGHT_PX,
                                            contain: 'strict',
                                            ...GRID_BG_STYLE,
                                        }}
                                        onClick={(e) =>
                                            handleEmptyCellClick(vehicle, e)
                                        }
                                    >
                                        {visibleDays.map((item) => {
                                            const date = addDays(
                                                epoch,
                                                item.index,
                                            );
                                            const services =
                                                servicesByDateVehicle
                                                    .get(date)
                                                    ?.get(vehicle.id) ?? [];
                                            return services.map((service) => {
                                                const position =
                                                    positionsByServiceId.get(
                                                        service.id,
                                                    );
                                                if (!position) return null;
                                                return (
                                                    <ServiceBar
                                                        key={service.id}
                                                        service={service}
                                                        position={position}
                                                        onClick={
                                                            handleServiceClick
                                                        }
                                                    />
                                                );
                                            });
                                        })}
                                    </div>
                                </div>
                            );
                        })}

                        {(() => {
                            const todayOffset = dayOffset(today, epoch);
                            if (todayOffset < 0 || todayOffset >= numDays) {
                                return null;
                            }
                            return (
                                <NowIndicator
                                    epoch={epoch}
                                    operationTz={operationTz}
                                    topOffsetPx={HEADER_HEIGHT_PX}
                                    leftOffsetPx={sidebarPx}
                                />
                            );
                        })()}

                        {vehicles.length === 0 && (
                            <div className="flex items-center justify-center py-12 text-sm text-muted-foreground">
                                No hay vehículos activos para mostrar.
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </TooltipProvider>
    );
}

/**
 * Convenience re-export for the page-level epoch computation.
 *
 * Half-window of 7 → 15-day canvas (week each direction). The Gantt
 * now slides this window forward / backward indefinitely as the user
 * scrolls past either edge (see the slide() flow inside HourlyGrid),
 * so this constant only sizes the initial render — not the total
 * navigable range. Operationally: ~13.7k px canvas, ~300 ServiceBar
 * peak instances; cheap to composite on low-end mobile GPUs.
 */
export function defaultEpochFor(today: Ymd, halfWindow = 7): Ymd {
    return addDays(today, -halfWindow);
}

export function defaultNumDays(halfWindow = 7): number {
    return halfWindow * 2 + 1;
}

export { dayOffset };
