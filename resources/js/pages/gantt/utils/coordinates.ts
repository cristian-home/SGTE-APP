/**
 * Continuous multi-day timeline coordinates for the infinite-scroll Gantt.
 *
 * The single-day version (`../gantt-utils.ts`) returns percentages
 * relative to a 24h slice; it is still used by the dashboard mini-Gantt
 * (`components/dashboard/today-services-gantt.tsx`). This module is for
 * `/gantt`, which lays every day side-by-side at a fixed
 * `pxPerHour` density and positions bars as absolute pixels from a
 * shared `epoch` date.
 *
 * All projections happen in the operation TZ (or each event's own TZ
 * when relevant) — never in UTC — so a service at 23:30 Bogotá lands on
 * its Bogotá day, not the UTC-rollover day.
 */

// 38px per hour matches the single-day grid that we ship today
// (24h × 38px = 912px per day; cf. `min-w-[1020px]` accounting for the
// sidebar). Keeping the same density means the visual continuity is
// preserved across days.
export const PX_PER_HOUR = 38;
export const HOURS_PER_DAY = 24;
export const PX_PER_DAY = PX_PER_HOUR * HOURS_PER_DAY;

/** YYYY-MM-DD string (validated by callers — no parsing here). */
export type Ymd = string;

/**
 * Whole days from `epoch` to `date`. Both are wall-clock Y-m-d strings
 * in the same TZ (operation TZ); calendar arithmetic ignores DST since
 * we operate on calendar days, not on instants between them.
 */
export function dayOffset(date: Ymd, epoch: Ymd): number {
    const [y1, m1, d1] = date.split('-').map(Number);
    const [y2, m2, d2] = epoch.split('-').map(Number);
    // UTC milliseconds to dodge any local-TZ DST nonsense — the two
    // dates we feed are calendar days, not instants, so any TZ works
    // as long as both use the same one. UTC is the simplest.
    const a = Date.UTC(y1, m1 - 1, d1);
    const b = Date.UTC(y2, m2 - 1, d2);
    return Math.round((a - b) / 86_400_000);
}

/**
 * Add (or subtract) whole days to a Y-m-d string in the operation TZ.
 * Wraps the same UTC trick as `dayOffset` — no DST surprises because we
 * never read the time-of-day.
 */
export function addDays(date: Ymd, days: number): Ymd {
    const [y, m, d] = date.split('-').map(Number);
    const t = Date.UTC(y, m - 1, d) + days * 86_400_000;
    const dt = new Date(t);
    const yyyy = dt.getUTCFullYear();
    const mm = String(dt.getUTCMonth() + 1).padStart(2, '0');
    const dd = String(dt.getUTCDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

/**
 * Convert a UTC instant to its absolute pixel on the timeline. The
 * pixel origin (0) corresponds to 00:00 of `epoch` in `tz`.
 *
 * Implementation: project the instant into the timeline's TZ via
 * `Intl.DateTimeFormat` (same approach as `instantToHoursInTz` in the
 * single-day utils), pull out date + hour + minute, then combine.
 */
export function instantToPxFromEpoch(
    at: string,
    tz: string,
    epoch: Ymd,
    pxPerHour: number = PX_PER_HOUR,
): number {
    const fmt = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: tz,
    });
    // en-CA renders as `YYYY-MM-DD, HH:MM`
    const parts = fmt.formatToParts(new Date(at));
    const get = (type: string) =>
        parts.find((p) => p.type === type)?.value ?? '00';
    const ymd: Ymd = `${get('year')}-${get('month')}-${get('day')}`;
    const hh = Number(get('hour'));
    const mm = Number(get('minute'));
    const days = dayOffset(ymd, epoch);
    const hoursIntoDay = hh + mm / 60;
    return (days * HOURS_PER_DAY + hoursIntoDay) * pxPerHour;
}

export interface AbsolutePosition {
    /** Pixels from the timeline origin (0 = start of epoch in tz). */
    left: number;
    /** Pixel width — never negative, always at least 1px so it renders. */
    width: number;
}

/**
 * Drop-in replacement for `serviceBarPosition` but on the continuous
 * multi-day canvas. Returns null only when duration is 0 (defensive —
 * shouldn't happen in practice). Services that cross midnight render
 * as one continuous bar across the day boundary.
 */
export function serviceBarAbsolutePosition(
    plannedStartAt: string,
    plannedDuration: number,
    eventTz: string,
    epoch: Ymd,
    pxPerHour: number = PX_PER_HOUR,
): AbsolutePosition | null {
    if (!plannedDuration || plannedDuration <= 0) {
        return null;
    }
    const left = instantToPxFromEpoch(
        plannedStartAt,
        eventTz,
        epoch,
        pxPerHour,
    );
    const width = Math.max((plannedDuration / 60) * pxPerHour, 1);
    return { left, width };
}

/**
 * Inverse projection: given an absolute pixel on the timeline (typically
 * `scroller.scrollLeft + clickX - timelineRect.left`), return the
 * calendar date and wall-clock HH:MM the user clicked. Used by the
 * "click empty cell to create service" handler.
 */
export function pixelToDateTime(
    absPx: number,
    epoch: Ymd,
    pxPerHour: number = PX_PER_HOUR,
): { date: Ymd; timeHHMM: string } {
    const totalHours = absPx / pxPerHour;
    const dayIndex = Math.floor(totalHours / HOURS_PER_DAY);
    const hoursIntoDay = totalHours - dayIndex * HOURS_PER_DAY;
    const hh = Math.max(0, Math.min(23, Math.floor(hoursIntoDay)));
    const mm = Math.max(0, Math.min(59, Math.floor((hoursIntoDay - hh) * 60)));
    const date = addDays(epoch, dayIndex);
    const timeHHMM = `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
    return { date, timeHHMM };
}
