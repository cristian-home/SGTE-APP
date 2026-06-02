import { MapPin } from 'lucide-react';
import { MapPreview } from '@/components/maps/map-preview';
import { cn } from '@/lib/utils';

interface RouteStaticMapProps {
    /** "lat,lng" string, or null when the origin is unknown. */
    origin: string | null;
    /** "lat,lng" string, or null when the destination is unknown. */
    destination: string | null;
    /**
     * Cached route as a GeoJSON LineString — array of [lng, lat] pairs
     * (matches `Service.route_geometry`). When absent, the preview falls
     * back to a straight line between the two markers.
     */
    geometry?: number[][] | null;
    className?: string;
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
 * Trip preview: A/B markers at origin/destination plus the route line.
 * Rendered locally with MapLibre + OpenStreetMap (no Google Static Maps
 * request) from the coordinates and the already-persisted route geometry.
 * Neutral placeholder when both coordinates are missing.
 */
export default function RouteStaticMap({
    origin,
    destination,
    geometry,
    className,
    height = 300,
}: RouteStaticMapProps) {
    const parsedOrigin = parseCoordinates(origin);
    const parsedDestination = parseCoordinates(destination);

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

    // One side known → single marker.
    if (!parsedOrigin || !parsedDestination) {
        const point = parsedOrigin ?? parsedDestination!;
        return (
            <MapPreview
                points={[{ lat: point.lat, lng: point.lng }]}
                height={height}
                className={className}
                ariaLabel={
                    parsedOrigin
                        ? 'Mapa con el origen del servicio'
                        : 'Mapa con el destino del servicio'
                }
            />
        );
    }

    const line =
        geometry && geometry.length >= 2
            ? geometry
            : [
                  [parsedOrigin.lng, parsedOrigin.lat],
                  [parsedDestination.lng, parsedDestination.lat],
              ];

    return (
        <MapPreview
            points={[
                {
                    lat: parsedOrigin.lat,
                    lng: parsedOrigin.lng,
                    color: '#34a853',
                },
                {
                    lat: parsedDestination.lat,
                    lng: parsedDestination.lng,
                    color: '#ea4335',
                },
            ]}
            line={line}
            height={height}
            className={className}
            ariaLabel="Mapa de la ruta entre el origen y el destino"
        />
    );
}
