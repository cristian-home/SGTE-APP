import { Head, usePage } from '@inertiajs/react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { index as ganttIndex } from '@/actions/App/Http/Controllers/GanttController';
import AppLayout from '@/layouts/app-layout';
import { viewerToday } from '@/lib/datetime';
import GanttHeader from './components/gantt-header';
import GanttLegend from './components/gantt-legend';
import HourlyGrid, {
    defaultEpochFor,
    defaultNumDays,
} from './components/hourly-grid';
import { useGanttDays } from './hooks/use-gantt-days';
import { addDays, dayOffset, type Ymd } from './utils/coordinates';

import type { BreadcrumbItem } from '@/types';
import type { DayStatus, Service, Vehicle } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Planificador Gantt', href: ganttIndex().url },
];

interface Props {
    vehicles: Vehicle[];
    services: Service[];
    dayStatus: DayStatus | null;
    date: string;
    canCreateServices: boolean;
}

export default function GanttIndex({
    vehicles,
    services,
    dayStatus,
    date,
    canCreateServices,
}: Props) {
    const sharedConfig = usePage().props.config as
        | { operation_tz?: string }
        | undefined;
    const operationTz = sharedConfig?.operation_tz ?? 'America/Bogota';

    // SSR date becomes the initial center of the timeline. The epoch
    // is `defaultEpochFor` days earlier (half-window). HourlyGrid
    // slides this internally whenever the user reaches an edge buffer
    // zone — pure state shift, no lock, no dim, no fetch coupling.
    const [epoch, setEpoch] = useState<Ymd>(() => defaultEpochFor(date));
    const numDays = useMemo(() => defaultNumDays(), []);

    const initialDay = useMemo(
        () => ({ date: date as Ymd, services, dayStatus }),
        [date, services, dayStatus],
    );

    const initialSeed = useMemo(() => [initialDay], [initialDay]);
    const { cache, ensureDay, isFetching } = useGanttDays({
        seed: initialSeed,
    });

    // `centerDate` mirrors the timeline's currently-centered day for
    // the header + URL sync.
    const [centerDate, setCenterDate] = useState<Ymd>(date as Ymd);

    // Out-of-range jumps need to re-center the viewport on the target
    // after epoch changes. Stored here so HourlyGrid's useLayoutEffect
    // can consume it on the next commit. Cleared after one render so
    // re-renders for unrelated reasons don't snap the scroll back.
    // `mode`: 'noon' for date-picker jumps (lands on midday of the
    // date), 'now' for the Hoy button (lands on the current instant
    // so the Now indicator sits centered).
    const [recenterTo, setRecenterTo] = useState<{
        date: Ymd;
        mode: 'noon' | 'now';
    } | null>(null);

    // Smooth-scroll callbacks exposed by HourlyGrid via onMount —
    // used for in-range targets where we just want the browser to
    // ease over to the destination (vs. the instant snap of an
    // out-of-range jump that goes through setEpoch + recenterTo).
    const jumpToDateRef = useRef<((date: Ymd) => void) | null>(null);
    const jumpToNowRef = useRef<(() => void) | null>(null);

    const handleCenterDateChange = useCallback((newDate: Ymd) => {
        setCenterDate(newDate);
        const url = new URL(window.location.href);
        url.searchParams.set('date', newDate);
        window.history.replaceState(window.history.state, '', url.toString());
    }, []);

    const handleJumpToDate = useCallback(
        (target: Ymd) => {
            const offset = dayOffset(target, epoch);
            const inRange = offset >= 0 && offset < numDays;
            if (inRange) {
                // Smooth scroll within the current window.
                jumpToDateRef.current?.(target);
                return;
            }
            // Out-of-range: re-center the window on the picked date.
            // Setting recenterTo + epoch in the same render lets
            // HourlyGrid's useLayoutEffect run once, snap scrollLeft to
            // the new center, and update the URL via the next scroll
            // tick. No lock, no dim — just a discrete jump.
            const newEpoch = addDays(target, -Math.floor(numDays / 2));
            setEpoch(newEpoch);
            setCenterDate(target);
            setRecenterTo({ date: target, mode: 'noon' });
            // Clear on next microtask so subsequent re-renders for
            // other reasons don't keep snapping scrollLeft back.
            queueMicrotask(() => setRecenterTo(null));
            const url = new URL(window.location.href);
            url.searchParams.set('date', target);
            window.history.replaceState(
                window.history.state,
                '',
                url.toString(),
            );
        },
        [epoch, numDays],
    );

    const handleJumpToNow = useCallback(() => {
        const target = viewerToday(operationTz) as Ymd;
        const offset = dayOffset(target, epoch);
        const inRange = offset >= 0 && offset < numDays;
        if (inRange) {
            // Today already in the window — smooth-scroll the viewport
            // so the Now line lands at center.
            jumpToNowRef.current?.();
            return;
        }
        // Out-of-range: snap epoch back to today + center on now
        // instant in one render. Same flow as the date-picker out-of-
        // range case, but recenterTo.mode = 'now'.
        const newEpoch = addDays(target, -Math.floor(numDays / 2));
        setEpoch(newEpoch);
        setCenterDate(target);
        setRecenterTo({ date: target, mode: 'now' });
        queueMicrotask(() => setRecenterTo(null));
        const url = new URL(window.location.href);
        url.searchParams.set('date', target);
        window.history.replaceState(window.history.state, '', url.toString());
    }, [epoch, numDays, operationTz]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Planificador Gantt" />
            <div className="flex max-h-[calc(100svh-4rem)] flex-col gap-4 p-4 md:max-h-[calc(100svh-5rem)]">
                <GanttHeader
                    date={centerDate}
                    canCreateServices={canCreateServices}
                    onJumpToDate={handleJumpToDate}
                    onJumpToNow={handleJumpToNow}
                />
                <GanttLegend />
                <div className="min-h-0 flex-1 overflow-hidden rounded-lg border">
                    <HourlyGrid
                        vehicles={vehicles}
                        initialDay={initialDay}
                        epoch={epoch}
                        onEpochChange={setEpoch}
                        operationTz={operationTz}
                        canCreateServices={canCreateServices}
                        cache={cache}
                        ensureDay={ensureDay}
                        isFetching={isFetching}
                        numDays={numDays}
                        onCenterDateChange={handleCenterDateChange}
                        onMount={({ jumpToDate, jumpToNow }) => {
                            jumpToDateRef.current = jumpToDate;
                            jumpToNowRef.current = jumpToNow;
                        }}
                        recenterTo={recenterTo}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
