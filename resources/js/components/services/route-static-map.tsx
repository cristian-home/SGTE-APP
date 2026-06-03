import { MapPin } from 'lucide-react';
import { memo, useMemo } from 'react';
import { MapDisabledPlaceholder } from '@/components/map-disabled-placeholder';
import { StaticMapImage } from '@/components/services/static-map-image';
import { useAppearance } from '@/hooks/use-appearance';
import {
    MAPS_ENABLED,
    staticMapUrl,
    staticRouteMapUrl,
} from '@/lib/google-maps';
import { cn } from '@/lib/utils';

interface RouteStaticMapProps {
    /** "lat,lng" string, or null when the origin is unknown. */
    origin: string | null;
    /** "lat,lng" string, or null when the destination is unknown. */
    destination: string | null;
    /**
     * Cached route as a GeoJSON LineString — array of [lng, lat] pairs
     * (matches `Service.route_geometry`). When absent, the static image
     * falls back to a straight line between the two markers.
     */
    geometry?: number[][] | null;
    className?: string;
    width?: number;
    height?: number;
}

function parseCoordinates(
    value: string | null,
): { lat: number; lng: number } | null {
    if (!value) {
        return null;
    }
    const match = /^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/.exec(value.trim());
    if (!match) {
        return null;
    }
    const lat = Number(match[1]);
    const lng = Number(match[2]);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return null;
    }
    return { lat, lng };
}

/**
 * Google Maps Static API preview that frames an entire trip: A/B
 * markers at origin and destination plus the polyline between them.
 * Renders a neutral placeholder when either coordinate is missing.
 *
 * The `<img>` is deferred to in-view + client-only via `StaticMapImage`
 * so it never appears in the SSR HTML (no first-paint fetch, no
 * SSR-light → hydration-dark double fetch). The URL is memoized so a
 * parent re-render (polling, partial reloads) doesn't rebuild a
 * theme-aware string that would otherwise count as a new billed image.
 */
function RouteStaticMapImpl({
    origin,
    destination,
    geometry,
    className,
    width = 600,
    height = 300,
}: RouteStaticMapProps) {
    const { resolvedAppearance } = useAppearance();
    const theme = resolvedAppearance === 'dark' ? 'dark' : 'light';

    const parsedOrigin = parseCoordinates(origin);
    const parsedDestination = parseCoordinates(destination);

    // Content key for the geometry array: it arrives as a fresh reference
    // from Inertia props, so depend on its shape (length + endpoints) — the
    // only thing that changes the rendered polyline for a given service —
    // instead of its identity, to keep the memo stable.
    const geometryKey =
        geometry && geometry.length
            ? `${geometry.length}:${geometry[0]?.join(',')}:${geometry[geometry.length - 1]?.join(',')}`
            : 'none';

    const src = useMemo(() => {
        if (!MAPS_ENABLED) {
            return null;
        }
        if (!parsedOrigin && !parsedDestination) {
            return null;
        }
        if (!parsedOrigin || !parsedDestination) {
            const point = parsedOrigin ?? parsedDestination!;
            return staticMapUrl({
                lat: point.lat,
                lng: point.lng,
                width,
                height,
                zoom: 13,
                theme,
            });
        }
        return staticRouteMapUrl({
            origin: parsedOrigin,
            destination: parsedDestination,
            geometry,
            theme,
            width,
            height,
        });
        // parsedOrigin/parsedDestination derive purely from the origin/
        // destination strings; geometry is captured via geometryKey.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [origin, destination, geometryKey, width, height, theme]);

    // Both sides unknown → neutral placeholder so the layout doesn't shift.
    if (!parsedOrigin && !parsedDestination) {
        return (
            <div
                className={cn(
                    'flex w-full flex-col items-center justify-center gap-1 rounded-md border border-dashed bg-muted/40 text-muted-foreground',
                    className,
                )}
                style={{ height }}
            >
                <MapPin className="size-5" />
                <span className="text-xs">Ruta no disponible</span>
            </div>
        );
    }

    // Maps disabled (e.g. local dev) → placeholder instead of a Google call.
    if (!MAPS_ENABLED || !src) {
        return (
            <div
                className={cn(
                    'w-full overflow-hidden rounded-md border',
                    className,
                )}
                style={{ height }}
            >
                <MapDisabledPlaceholder />
            </div>
        );
    }

    const altLabel =
        !parsedOrigin || !parsedDestination
            ? parsedOrigin
                ? 'Mapa con el origen del servicio'
                : 'Mapa con el destino del servicio'
            : 'Mapa de la ruta entre el origen y el destino';

    return (
        <StaticMapImage
            src={src}
            alt={altLabel}
            width={width}
            height={height}
            className={className}
        />
    );
}

export default memo(RouteStaticMapImpl);
