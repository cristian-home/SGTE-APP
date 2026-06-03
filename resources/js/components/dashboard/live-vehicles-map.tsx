import { Link, router } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { MapDisabledPlaceholder } from '@/components/map-disabled-placeholder';
import { StaticMapImage } from '@/components/services/static-map-image';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/hooks/use-appearance';
import { MAPS_ENABLED, staticVehiclesMapUrl } from '@/lib/google-maps';

const REFRESH_INTERVAL_MS = 60_000;
const MAP_WIDTH = 600;
const MAP_HEIGHT = 280;

export type DashboardActiveVehicle = {
    vehicle_plate: string;
    service_id: number;
    location: {
        lat: number;
        lng: number;
        recorded_at: string | null;
    };
};

/**
 * Mini map for the dashboard showing one pin per vehicle with an open
 * service today (data from DashboardController::buildActiveVehicles via
 * App\Support\VehicleLocationResolver).
 *
 * Rendered as a Google Maps **Static** image — NOT the interactive Dynamic
 * Maps JS API — so the dashboard "quick glance" never triggers a billed Map
 * Load. The `<img>` is deferred to in-view + client-only via StaticMapImage.
 * Data still refreshes every 60s; when a vehicle moves, the memoized URL
 * changes and a new (cheap) static image is fetched. Interactivity
 * (click-through to a service, pan/zoom) lives on the full /gps/map, linked
 * from the header.
 */
export function LiveVehiclesMap({
    vehicles,
    className,
}: {
    vehicles: DashboardActiveVehicle[];
    className?: string;
}) {
    const { resolvedAppearance } = useAppearance();
    const theme = resolvedAppearance === 'dark' ? 'dark' : 'light';

    useEffect(() => {
        const interval = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }
            router.reload({ only: ['activeVehicles'] });
        }, REFRESH_INTERVAL_MS);
        return () => clearInterval(interval);
    }, []);

    const src = useMemo(() => {
        if (!MAPS_ENABLED || vehicles.length === 0) {
            return null;
        }
        return staticVehiclesMapUrl({
            vehicles: vehicles.map((v) => ({
                lat: v.location.lat,
                lng: v.location.lng,
            })),
            width: MAP_WIDTH,
            height: MAP_HEIGHT,
            theme,
        });
    }, [vehicles, theme]);

    return (
        <Card className={className}>
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <MapPin
                            className="size-4 text-muted-foreground"
                            aria-hidden
                        />
                        Vehículos activos
                    </CardTitle>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/gps/map">Ver mapa →</Link>
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {vehicles.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Sin ubicaciones recientes.
                    </p>
                ) : !MAPS_ENABLED || !src ? (
                    <div
                        className="w-full overflow-hidden rounded-md border"
                        style={{ height: MAP_HEIGHT }}
                    >
                        <MapDisabledPlaceholder />
                    </div>
                ) : (
                    <StaticMapImage
                        src={src}
                        alt={`Mapa con ${vehicles.length} vehículo(s) activo(s)`}
                        width={MAP_WIDTH}
                        height={MAP_HEIGHT}
                    />
                )}
            </CardContent>
        </Card>
    );
}
