import { useEffect, useState } from 'react';

/**
 * Returns false on the first render, then true after mount.
 *
 * Used to defer crash-prone third-party subtrees (Google Maps) by one
 * commit. Inertia v2 swaps pages inside a `flushSync`; an error thrown
 * synchronously during that commit (e.g. an `<AdvancedMarker>` attaching
 * to an auth-failed map whose script is already loaded from another page)
 * escapes React error boundaries and blanks the whole SPA. Mounting the
 * subtree on the *next* render moves any such throw into a normal commit,
 * where the surrounding <ErrorBoundary> can catch it and show a fallback.
 */
export function useDeferredMount(): boolean {
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        // Defer to the next frame (not synchronously in the effect) so the
        // mount is fully decoupled from the Inertia swap's flushSync commit.
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);

    return mounted;
}
