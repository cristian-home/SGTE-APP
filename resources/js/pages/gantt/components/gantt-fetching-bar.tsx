import { useEffect, useState } from 'react';

interface Props {
    /** When true, the bar is meant to be visible (subject to delayMs). */
    isFetching: boolean;
    /**
     * Wait this long before painting the bar. Suppresses flicker for
     * fast fetches (~80% of cache misses come back in <100ms when the
     * day is already in the prefetch window).
     */
    delayMs?: number;
}

/**
 * Thin indeterminate progress bar that sits at the top of the Gantt
 * grid (just below the page-level controls header). Renders only when
 * at least one day request has been in-flight for longer than
 * `delayMs`. Pure CSS animation, no layout cost — uses the
 * `indeterminate-bar` keyframe defined in `app.css`.
 */
export default function GanttFetchingBar({ isFetching, delayMs = 200 }: Props) {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        if (!isFetching) {
            return;
        }
        const id = window.setTimeout(() => setVisible(true), delayMs);
        // Hide on cleanup — runs when `isFetching` flips back to false (or
        // on unmount), so the bar disappears immediately without a
        // synchronous setState in the effect body.
        return () => {
            window.clearTimeout(id);
            setVisible(false);
        };
    }, [isFetching, delayMs]);

    if (!visible) return null;

    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-x-0 top-0 z-50 h-0.5 overflow-hidden bg-primary/10"
        >
            <div
                className="h-full w-1/4 bg-primary"
                style={{
                    animation: 'indeterminate-bar 1.4s ease-in-out infinite',
                }}
            />
        </div>
    );
}
