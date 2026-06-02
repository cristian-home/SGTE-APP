import { memo } from 'react';
import { useInViewMount } from '@/hooks/use-in-view-mount';
import { cn } from '@/lib/utils';

interface StaticMapImageProps {
    /** Fully-built Maps Static API URL. The caller memoizes it. */
    src: string;
    alt: string;
    width: number;
    height: number;
    className?: string;
}

/**
 * Renders a Google Maps Static API `<img>` only once the element has
 * scrolled into view AND we're on the client — never during SSR.
 *
 * Why: `ssr.tsx` runs `renderToString`, so emitting the `<img>` server-side
 * bakes a Static Maps URL into the HTML that the browser fetches on first
 * paint; a dark-mode user then re-renders to a different (dark) URL on
 * hydration and fetches a SECOND time. `useInViewMount` returns `inView`
 * false on the server snapshot and on the first client render (the observer
 * effect flips it post-commit), so the server HTML and the first client
 * paint both render the no-network skeleton — no SSR image, no double fetch,
 * no hydration mismatch. The real `<img>` is requested only when the user
 * actually scrolls the preview into view. ToS-clean: the request still hits
 * Google's API directly client-side; we just defer it.
 */
function StaticMapImageImpl({
    src,
    alt,
    width,
    height,
    className,
}: StaticMapImageProps) {
    const { ref, inView } = useInViewMount<HTMLDivElement>();

    return (
        <div
            ref={ref}
            style={{ height }}
            className={cn(
                'w-full overflow-hidden rounded-md border bg-muted/30',
                className,
            )}
        >
            {inView ? (
                <img
                    src={src}
                    alt={alt}
                    width={width}
                    height={height}
                    loading="lazy"
                    className="size-full object-cover"
                />
            ) : (
                <div className="size-full animate-pulse bg-muted/40" />
            )}
        </div>
    );
}

export const StaticMapImage = memo(StaticMapImageImpl);
