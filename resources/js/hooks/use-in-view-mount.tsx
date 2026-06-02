import { useEffect, useRef, useState } from 'react';

/**
 * Defers mounting an expensive subtree (a Google Map) until it actually
 * scrolls into view, then keeps it mounted. Avoids a billed "Map Load" on
 * every dashboard visit when the user may never look at the map.
 *
 * The IntersectionObserver callback fires after layout (a normal commit),
 * so this also keeps the map out of Inertia's swapComponent flushSync —
 * preserving the ErrorBoundary catch (see use-deferred-mount).
 *
 * @returns a ref to attach to the container, and whether to mount yet.
 */
export function useInViewMount<T extends HTMLElement = HTMLDivElement>(): {
    ref: React.RefObject<T | null>;
    inView: boolean;
} {
    const ref = useRef<T | null>(null);
    const [inView, setInView] = useState(false);

    useEffect(() => {
        if (inView) {
            return;
        }
        const el = ref.current;
        if (!el || typeof IntersectionObserver === 'undefined') {
            // No observer (SSR/old browser) → mount eagerly as a fallback.
            setInView(true);
            return;
        }
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((e) => e.isIntersecting)) {
                    setInView(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '100px' },
        );
        observer.observe(el);
        return () => observer.disconnect();
    }, [inView]);

    return { ref, inView };
}
