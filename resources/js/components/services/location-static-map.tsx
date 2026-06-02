import { MapPin } from 'lucide-react';
import { MapPreview } from '@/components/maps/map-preview';
import { cn } from '@/lib/utils';

interface LocationStaticMapProps {
    /** "lat,lng" string, or null when the location is unknown. */
    coordinates: string | null;
    /** "Origen" / "Destino" — used for the aria label and empty-state copy. */
    label: string;
    className?: string;
    height?: number;
}

/**
 * Parse a "lat,lng" string into a coordinate pair. Returns null for
 * empty or malformed input so the caller can render the empty state.
 */
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
 * Single-coordinate preview rendered locally with MapLibre + OpenStreetMap
 * (no Google Static Maps request). Neutral "Sin ubicación" placeholder when
 * the coordinates are absent or unparseable.
 */
export default function LocationStaticMap({
    coordinates,
    label,
    className,
    height = 160,
}: LocationStaticMapProps) {
    const parsed = parseCoordinates(coordinates);

    if (!parsed) {
        return (
            <div
                className={cn(
                    'flex w-full flex-col items-center justify-center gap-1 rounded-md border border-dashed bg-muted/40 text-muted-foreground',
                    className,
                )}
                style={{ height }}
            >
                <MapPin className="size-5" />
                <span className="text-xs">Sin ubicación</span>
            </div>
        );
    }

    return (
        <MapPreview
            points={[{ lat: parsed.lat, lng: parsed.lng }]}
            height={height}
            className={className}
            ariaLabel={`Mapa de ${label.toLowerCase()}`}
        />
    );
}
