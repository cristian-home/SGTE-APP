/// <reference types="google.maps" />

import { Link, router } from '@inertiajs/react';
import {
    AdvancedMarker,
    APIProvider,
    Map as GoogleMap,
    Pin,
    useMap,
} from '@vis.gl/react-google-maps';
import { MapPin } from 'lucide-react';
import { useEffect } from 'react';
import { ErrorBoundary } from '@/components/error-boundary';
import { MapUnavailable } from '@/components/map-unavailable';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/hooks/use-appearance';
import { useDeferredMount } from '@/hooks/use-deferred-mount';
import {
    GOOGLE_MAPS_BROWSER_KEY,
    GOOGLE_MAPS_MAP_ID,
    MEDELLIN_CENTER,
    MEDELLIN_ZOOM,
} from '@/lib/google-maps';

const REFRESH_INTERVAL_MS = 60_000;

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
 * Mini live-map for the dashboard. Shows one marker per vehicle with
 * an open service today (data from DashboardController::buildActiveVehicles
 * via App\Support\VehicleLocationResolver). Polls every 60s (faster
 * than the full /gps/map's 300s — dashboard is the "quick glance"
 * surface). Click marker → service detail; "Ver mapa completo" → full
 * GPS map.
 */
export function LiveVehiclesMap({
    vehicles,
    className,
}: {
    vehicles: DashboardActiveVehicle[];
    className?: string;
}) {
    const { resolvedAppearance } = useAppearance();
    // Defer the map past the Inertia page swap so a Google Maps marker
    // crash lands in a normal commit the ErrorBoundary catches instead of
    // escaping swapComponent's flushSync and blanking the page.
    const mapReady = useDeferredMount();

    useEffect(() => {
        const interval = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }
            router.reload({ only: ['activeVehicles'] });
        }, REFRESH_INTERVAL_MS);
        return () => clearInterval(interval);
    }, []);

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
                ) : (
                    <div className="h-70 w-full overflow-hidden rounded-md border">
                        <ErrorBoundary
                            fallback={({ reset }) => (
                                <MapUnavailable reset={reset} />
                            )}
                        >
                            {!mapReady ? (
                                <div className="size-full animate-pulse bg-muted/30" />
                            ) : (
                                <APIProvider apiKey={GOOGLE_MAPS_BROWSER_KEY}>
                                    <GoogleMap
                                        mapId={GOOGLE_MAPS_MAP_ID}
                                        defaultCenter={MEDELLIN_CENTER}
                                        defaultZoom={MEDELLIN_ZOOM}
                                        gestureHandling="cooperative"
                                        disableDefaultUI
                                        colorScheme={
                                            resolvedAppearance === 'dark'
                                                ? 'DARK'
                                                : 'LIGHT'
                                        }
                                    >
                                        <FitVehicleBounds vehicles={vehicles} />
                                        {vehicles.map((vehicle) => (
                                            <AdvancedMarker
                                                key={vehicle.service_id}
                                                position={{
                                                    lat: vehicle.location.lat,
                                                    lng: vehicle.location.lng,
                                                }}
                                                title={vehicle.vehicle_plate}
                                                onClick={() =>
                                                    router.visit(
                                                        `/services/${vehicle.service_id}`,
                                                    )
                                                }
                                            >
                                                <Pin
                                                    background="var(--primary)"
                                                    borderColor="var(--primary)"
                                                    glyphColor="var(--primary-foreground)"
                                                />
                                            </AdvancedMarker>
                                        ))}
                                    </GoogleMap>
                                </APIProvider>
                            )}
                        </ErrorBoundary>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function FitVehicleBounds({
    vehicles,
}: {
    vehicles: DashboardActiveVehicle[];
}) {
    const map = useMap();
    useEffect(() => {
        if (!map || vehicles.length === 0) return;
        if (vehicles.length === 1) {
            map.panTo({
                lat: vehicles[0].location.lat,
                lng: vehicles[0].location.lng,
            });
            map.setZoom(14);
            return;
        }
        const bounds = new google.maps.LatLngBounds();
        for (const v of vehicles) {
            bounds.extend({ lat: v.location.lat, lng: v.location.lng });
        }
        map.fitBounds(bounds, 48);
    }, [map, vehicles]);
    return null;
}
