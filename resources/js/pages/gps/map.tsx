import { Head, router } from '@inertiajs/react';
import {
    APIProvider,
    AdvancedMarker,
    Map as GoogleMap,
} from '@vis.gl/react-google-maps';
import { List } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ErrorBoundary } from '@/components/error-boundary';
import { FitBounds } from '@/components/gps/fit-bounds';
import { FocusService } from '@/components/gps/focus-service';
import { RoutePolyline } from '@/components/gps/route-polyline';
import { ServicesPanel } from '@/components/gps/services-panel';
import { VehicleMarker } from '@/components/gps/vehicle-marker';
import { MapDisabledPlaceholder } from '@/components/map-disabled-placeholder';
import { MapUnavailable } from '@/components/map-unavailable';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useAppearance } from '@/hooks/use-appearance';
import { useDeferredMount } from '@/hooks/use-deferred-mount';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';
import {
    GOOGLE_MAPS_BROWSER_KEY,
    GOOGLE_MAPS_MAP_ID,
    MAPS_ENABLED,
    MEDELLIN_CENTER,
    MEDELLIN_ZOOM,
} from '@/lib/google-maps';
import { serviceColor, toLatLng } from '@/lib/gps-map';
import type { BreadcrumbItem } from '@/types';
import type { ActiveService, MarkerService, RouteData } from '@/types/gps-map';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'GPS', href: '#' },
    { title: 'Mapa', href: '/gps/map' },
];

const REFRESH_INTERVAL_MS = 300_000;

export default function GpsMap({
    activeServices,
}: {
    activeServices: ActiveService[];
}) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [sheetOpen, setSheetOpen] = useState(false);
    // The drawer trigger + sheet are mobile-only; gating the render on
    // this keeps them out of the desktop DOM entirely (no hidden nodes).
    const isMobile = useIsMobile();
    // Drive the Google map's colour scheme from the app theme.
    const { resolvedAppearance } = useAppearance();
    // Defer the map one commit past the Inertia page swap so a Google
    // Maps marker crash lands in a normal commit the ErrorBoundary catches
    // (rather than escaping the swapComponent flushSync). See the hook.
    const mapReady = useDeferredMount();

    useEffect(() => {
        // Skip the auto-refresh when the tab is hidden — Inertia v2
        // triggers View Transitions on successful responses, and the
        // browser throws InvalidStateError when the document isn't
        // visible. The next refresh after the tab is refocused picks
        // up whatever changed while we were away.
        const interval = setInterval(() => {
            if (typeof document !== 'undefined' && document.hidden) {
                return;
            }
            router.reload({ only: ['activeServices'] });
        }, REFRESH_INTERVAL_MS);
        return () => clearInterval(interval);
    }, []);

    // Picking a service from the panel focuses it on the map; on mobile
    // it also closes the slide-in sheet so the map is visible.
    const handleSelect = (id: number): void => {
        setSelectedId(id);
        setSheetOpen(false);
    };

    const markerServices = useMemo<MarkerService[]>(
        () =>
            activeServices
                .filter((s) => s.location !== null)
                .map((s) => ({
                    service_id: s.service_id,
                    vehicle_plate: s.vehicle_plate,
                    driver_name: s.driver_name,
                    position: {
                        lat: s.location!.latitude,
                        lng: s.location!.longitude,
                    },
                    is_manual: s.location!.is_manual,
                    recorded_at: s.location!.recorded_at,
                    route_distance_m: s.route_distance_m,
                    route_duration_s: s.route_duration_s,
                })),
        [activeServices],
    );

    const routes = useMemo<RouteData[]>(
        () =>
            activeServices
                .filter((s) => s.origin !== null && s.destination !== null)
                .map((s) => {
                    const hasRealRoute =
                        s.route !== null && s.route.length >= 2;
                    return {
                        service_id: s.service_id,
                        color: serviceColor(s.service_id),
                        origin: toLatLng(s.origin!),
                        destination: toLatLng(s.destination!),
                        confirmed: hasRealRoute,
                        path: hasRealRoute
                            ? s.route!.map(toLatLng)
                            : [toLatLng(s.origin!), toLatLng(s.destination!)],
                    };
                }),
        [activeServices],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mapa GPS" />
            <div
                className="flex flex-1 flex-col gap-2 rounded-xl p-4"
                // Tailwind 4.3.0 silently drops `h-[calc(100vh-Xrem)]` for
                // some values (notably 6rem), so go inline. 5rem matches
                // the actual chrome: header h-16 (64px) + main m-2 (16px).
                style={{ height: 'calc(100vh - 5rem)' }}
            >
                <div className="flex items-center justify-between gap-3">
                    <div className="text-xs text-muted-foreground">
                        {markerServices.length} de {activeServices.length}{' '}
                        servicios activos con ubicación conocida. Actualización
                        automática cada {REFRESH_INTERVAL_MS / 60_000} min.
                    </div>
                    {/* Mobile-only trigger + drawer for the services panel —
                        rendered only on mobile so it never reaches the DOM
                        on desktop. */}
                    {isMobile && (
                        <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                            <SheetTrigger asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0"
                                >
                                    <List className="size-4" />
                                    Servicios ({activeServices.length})
                                </Button>
                            </SheetTrigger>
                            <SheetContent
                                side="left"
                                className="w-[256px] gap-0 p-0 sm:max-w-sm"
                            >
                                <SheetHeader className="sr-only">
                                    <SheetTitle>Servicios activos</SheetTitle>
                                </SheetHeader>
                                <ServicesPanel
                                    services={activeServices}
                                    selectedId={selectedId}
                                    onSelect={handleSelect}
                                />
                            </SheetContent>
                        </Sheet>
                    )}
                </div>

                <div className="flex min-h-0 flex-1 gap-2">
                    {/* Desktop-only services sidebar. */}
                    {!isMobile && (
                        <aside className="flex w-[256px] shrink-0 overflow-hidden rounded-md border bg-card">
                            <ServicesPanel
                                services={activeServices}
                                selectedId={selectedId}
                                onSelect={handleSelect}
                            />
                        </aside>
                    )}

                    <div className="relative min-h-0 flex-1 overflow-hidden rounded-md border">
                        <ErrorBoundary
                            fallback={({ reset }) => (
                                <MapUnavailable reset={reset} />
                            )}
                        >
                            {!MAPS_ENABLED ? (
                                <MapDisabledPlaceholder />
                            ) : !mapReady ? (
                                <div className="size-full animate-pulse bg-muted/30" />
                            ) : (
                                <APIProvider apiKey={GOOGLE_MAPS_BROWSER_KEY}>
                                    <GoogleMap
                                        colorScheme={
                                            resolvedAppearance === 'dark'
                                                ? 'DARK'
                                                : 'LIGHT'
                                        }
                                        mapId={GOOGLE_MAPS_MAP_ID}
                                        defaultCenter={MEDELLIN_CENTER}
                                        defaultZoom={MEDELLIN_ZOOM}
                                        gestureHandling="greedy"
                                        clickableIcons={false}
                                        streetViewControl={false}
                                        className="size-full"
                                    >
                                        <FitBounds services={activeServices} />
                                        <FocusService
                                            selectedId={selectedId}
                                            services={activeServices}
                                        />

                                        {routes.map((route) => (
                                            <RoutePolyline
                                                key={`route-${route.service_id}`}
                                                path={route.path}
                                                color={route.color}
                                                confirmed={route.confirmed}
                                                selected={
                                                    selectedId ===
                                                    route.service_id
                                                }
                                                dimmed={
                                                    selectedId !== null &&
                                                    selectedId !==
                                                        route.service_id
                                                }
                                            />
                                        ))}

                                        {routes.map((route) => {
                                            const dimmed =
                                                selectedId !== null &&
                                                selectedId !== route.service_id;
                                            return (
                                                <AdvancedMarker
                                                    key={`origin-${route.service_id}`}
                                                    position={route.origin}
                                                >
                                                    <span
                                                        className="block size-3.5 rounded-full transition-opacity"
                                                        style={{
                                                            background:
                                                                route.color,
                                                            border: `2px solid ${route.color}`,
                                                            opacity: dimmed
                                                                ? 0.3
                                                                : 1,
                                                        }}
                                                    />
                                                </AdvancedMarker>
                                            );
                                        })}

                                        {routes.map((route) => {
                                            const dimmed =
                                                selectedId !== null &&
                                                selectedId !== route.service_id;
                                            return (
                                                <AdvancedMarker
                                                    key={`destination-${route.service_id}`}
                                                    position={route.destination}
                                                >
                                                    <span
                                                        className="block size-3.5 rounded-full bg-white transition-opacity"
                                                        style={{
                                                            border: `2px solid ${route.color}`,
                                                            opacity: dimmed
                                                                ? 0.3
                                                                : 1,
                                                        }}
                                                    />
                                                </AdvancedMarker>
                                            );
                                        })}

                                        {markerServices.map((service) => (
                                            <VehicleMarker
                                                key={service.service_id}
                                                service={service}
                                                open={
                                                    selectedId ===
                                                    service.service_id
                                                }
                                                onOpenChange={(isOpen) =>
                                                    setSelectedId(
                                                        isOpen
                                                            ? service.service_id
                                                            : null,
                                                    )
                                                }
                                            />
                                        ))}
                                    </GoogleMap>
                                </APIProvider>
                            )}
                        </ErrorBoundary>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
