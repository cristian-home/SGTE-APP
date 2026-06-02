import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

export interface MapPreviewPoint {
    lat: number;
    lng: number;
    /** Marker color (CSS). Defaults to red. */
    color?: string;
}

interface MapPreviewProps {
    /** 1–2 markers (e.g. origin/destination). */
    points: MapPreviewPoint[];
    /** Route as [lng, lat] pairs (matches Service.route_geometry). */
    line?: number[][] | null;
    height?: number;
    className?: string;
    ariaLabel?: string;
}

/**
 * Non-interactive map preview rendered with MapLibre GL + free OpenStreetMap
 * raster tiles — replaces the Google Static Maps `<img>` so route/location
 * previews cost nothing per view and don't depend on Google. Draws from data
 * already persisted (Service.route_geometry + coordinates), so no Google call
 * is needed to build them.
 */
export function MapPreview({
    points,
    line,
    height = 300,
    className,
    ariaLabel,
}: MapPreviewProps) {
    const containerRef = useRef<HTMLDivElement | null>(null);
    // Stable signature so the map only re-initializes when the geometry
    // actually changes, not on every parent re-render.
    const signature = JSON.stringify([points, line]);

    useEffect(() => {
        const el = containerRef.current;
        if (!el || points.length === 0) {
            return;
        }

        const map = new maplibregl.Map({
            container: el,
            style: {
                version: 8,
                sources: {
                    osm: {
                        type: 'raster',
                        tiles: [
                            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                        ],
                        tileSize: 256,
                        attribution: '© OpenStreetMap',
                    },
                },
                layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
            },
            interactive: false,
            attributionControl: { compact: true },
        });

        map.on('load', () => {
            if (line && line.length >= 2) {
                map.addSource('route', {
                    type: 'geojson',
                    data: {
                        type: 'Feature',
                        properties: {},
                        geometry: { type: 'LineString', coordinates: line },
                    },
                });
                map.addLayer({
                    id: 'route',
                    type: 'line',
                    source: 'route',
                    paint: { 'line-color': '#4285F4', 'line-width': 4 },
                });
            }

            for (const p of points) {
                new maplibregl.Marker({ color: p.color ?? '#ea4335' })
                    .setLngLat([p.lng, p.lat])
                    .addTo(map);
            }

            const coords: [number, number][] = points.map((p) => [
                p.lng,
                p.lat,
            ]);
            if (line) {
                for (const c of line) {
                    coords.push([c[0], c[1]]);
                }
            }
            if (coords.length === 1) {
                map.setCenter(coords[0]);
                map.setZoom(14);
            } else if (coords.length > 1) {
                const bounds = coords.reduce(
                    (b, c) => b.extend(c),
                    new maplibregl.LngLatBounds(coords[0], coords[0]),
                );
                map.fitBounds(bounds, {
                    padding: 40,
                    animate: false,
                    maxZoom: 15,
                });
            }
        });

        return () => map.remove();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [signature]);

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={ariaLabel}
            className={cn(
                'w-full overflow-hidden rounded-md border',
                className,
            )}
            style={{ height }}
        />
    );
}
