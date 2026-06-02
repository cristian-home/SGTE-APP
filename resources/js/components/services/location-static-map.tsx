import { MapPin } from 'lucide-react';
import { MapDisabledPlaceholder } from '@/components/map-disabled-placeholder';
import { useAppearance } from '@/hooks/use-appearance';
import { MAPS_ENABLED, staticMapUrl } from '@/lib/google-maps';
import { cn } from '@/lib/utils';

interface LocationStaticMapProps {
    /** "lat,lng" string, or null when the location is unknown. */
    coordinates: string | null;
    /** "Origen" / "Destino" — used for the alt text and empty-state copy. */
    label: string;
    className?: string;
    width?: number;
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
 * Google Maps Static API preview for a single coordinate. Renders a
 * neutral "Sin ubicación" placeholder (never a broken image) when the
 * coordinates are absent or unparseable.
 */
export default function LocationStaticMap({
    coordinates,
    label,
    className,
    width = 300,
    height = 160,
}: LocationStaticMapProps) {
    const { resolvedAppearance } = useAppearance();
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

    // Maps disabled (e.g. local dev) → placeholder instead of an <img>
    // that would hit Google's Static Maps API. See MAPS_ENABLED.
    if (!MAPS_ENABLED) {
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

    return (
        <img
            src={staticMapUrl({
                lat: parsed.lat,
                lng: parsed.lng,
                width,
                height,
                theme: resolvedAppearance === 'dark' ? 'dark' : 'light',
            })}
            alt={`Mapa de ${label.toLowerCase()}`}
            width={width}
            height={height}
            loading="lazy"
            className={cn(
                'h-auto w-full rounded-md border object-cover',
                className,
            )}
        />
    );
}
