/**
 * Google Maps Platform client-side configuration.
 *
 * The browser key is injected at build-time via the
 * `VITE_GOOGLE_MAPS_BROWSER_KEY` env variable, which is itself
 * interpolated from `GOOGLE_MAPS_BROWSER_KEY` in `.env` (see
 * `.env.example`). Same source of truth the backend reads via
 * `config('services.google_maps.browser_key')`. `GOOGLE_MAPS_MAP_ID`
 * identifies the vector map required by `<Map>` + `<AdvancedMarker>`.
 *
 * Scope: the browser key is HTTP-referrer restricted in the Google Cloud
 * console so a leak in the JS bundle doesn't translate into account
 * abuse. The Map ID is not a secret.
 */
export const GOOGLE_MAPS_BROWSER_KEY: string =
    (import.meta.env.VITE_GOOGLE_MAPS_BROWSER_KEY as string | undefined) ?? '';

export const GOOGLE_MAPS_MAP_ID: string =
    (import.meta.env.VITE_GOOGLE_MAPS_MAP_ID as string | undefined) ?? '';

/**
 * Master switch for ALL Google Maps usage (dynamic maps + geocoding).
 * Set `VITE_GOOGLE_MAPS_ENABLED=false` in local `.env` to render
 * placeholders instead of real maps — every Google request from dev is a
 * billed call, and HMR + StrictMode multiply map mounts, which is how a
 * single dev on localhost ran up the bill. Defaults to enabled so
 * staging/prod are unaffected unless they opt out.
 */
export const MAPS_ENABLED: boolean =
    (import.meta.env.VITE_GOOGLE_MAPS_ENABLED as string | undefined) !==
    'false';

if (import.meta.env.PROD && !GOOGLE_MAPS_BROWSER_KEY) {
    console.warn(
        'VITE_GOOGLE_MAPS_BROWSER_KEY is empty — maps, address autocomplete and static-map previews will fail.',
    );
}

/** Default map center: Medellín — matches the historical /gps/map view. */
export const MEDELLIN_CENTER: { lat: number; lng: number } = {
    lat: 6.2518,
    lng: -75.5636,
};
export const MEDELLIN_ZOOM = 11;

/**
 * Fallback center for the manual map picker when no municipality is
 * selected (country-level Bogotá view).
 */
export const BOGOTA_FALLBACK: { lat: number; lng: number } = {
    lat: 4.711,
    lng: -74.0721,
};

interface StaticMapOptions {
    lat: number;
    lng: number;
    zoom?: number;
    width?: number;
    height?: number;
    scale?: number;
    /**
     * Map color scheme. `'dark'` appends the same `style=` rules as
     * `staticRouteMapUrl` so a single-point preview matches the rest of
     * the app's dark theme. Shared `DARK_MAP_STYLE` constant below.
     */
    theme?: 'light' | 'dark';
}

/**
 * Build a Maps Static API URL with a single red marker pinned at the
 * coordinate. `scale=2` requests a 2x raster so the preview stays crisp
 * on retina/HiDPI displays.
 */
export function staticMapUrl({
    lat,
    lng,
    zoom = 15,
    width = 300,
    height = 160,
    scale = 2,
    theme = 'light',
}: StaticMapOptions): string {
    const params = new URLSearchParams();
    params.append('center', `${lat},${lng}`);
    params.append('zoom', String(zoom));
    params.append('size', `${width}x${height}`);
    params.append('scale', String(scale));
    params.append('markers', `color:red|${lat},${lng}`);
    if (theme === 'dark') {
        for (const rule of DARK_MAP_STYLE) {
            params.append('style', rule);
        }
    }
    params.append('key', GOOGLE_MAPS_BROWSER_KEY);
    return `https://maps.googleapis.com/maps/api/staticmap?${params.toString()}`;
}

interface LatLng {
    lat: number;
    lng: number;
}

interface StaticVehiclesMapOptions {
    /** One pin per active vehicle. */
    vehicles: LatLng[];
    width?: number;
    height?: number;
    scale?: number;
    theme?: 'light' | 'dark';
}

/**
 * Build a Maps Static API URL with one red pin per active vehicle.
 *
 * Used by the dashboard "Vehículos activos" card, which renders a static
 * image instead of the interactive (billed-per-load) Dynamic Maps JS API.
 * With two or more pins the Static API auto-frames them (no `center`/`zoom`
 * needed); with a single pin we center + zoom so it isn't framed at world
 * scale. All positions share one `markers` style param to keep the URL
 * compact (a handful of fleet vehicles stays far under the ~8 KB cap).
 */
export function staticVehiclesMapUrl({
    vehicles,
    width = 600,
    height = 280,
    scale = 2,
    theme = 'light',
}: StaticVehiclesMapOptions): string {
    const params = new URLSearchParams();
    params.append('size', `${width}x${height}`);
    params.append('scale', String(scale));

    if (vehicles.length === 1) {
        params.append('center', `${vehicles[0].lat},${vehicles[0].lng}`);
        params.append('zoom', '14');
    }

    const positions = vehicles.map((v) => `${v.lat},${v.lng}`).join('|');
    params.append('markers', `color:red|${positions}`);

    if (theme === 'dark') {
        for (const rule of DARK_MAP_STYLE) {
            params.append('style', rule);
        }
    }

    params.append('key', GOOGLE_MAPS_BROWSER_KEY);
    return `https://maps.googleapis.com/maps/api/staticmap?${params.toString()}`;
}

interface StaticRouteMapOptions {
    origin: LatLng;
    destination: LatLng;
    /**
     * Optional GeoJSON LineString — array of [lng, lat] pairs as stored
     * in `Service.route_geometry`. When provided, the encoded polyline
     * is drawn between the markers; otherwise the static API auto-fits
     * the two markers and the caller falls back to a straight `path`.
     */
    geometry?: number[][] | null;
    /**
     * Map color scheme. `'dark'` appends a set of `style=` rules that
     * mirror Google's "Night mode" palette so the image fits naturally
     * inside the app's dark theme.
     */
    theme?: 'light' | 'dark';
    width?: number;
    height?: number;
    scale?: number;
}

/**
 * Static Maps API style rules for a dark colour scheme. Each entry is
 * a single `feature:…|element:…|color:…` chain appended as one
 * `style=` parameter; URLSearchParams accepts repeated keys, so the
 * rules accumulate in URL order — the same way the Maps JS API
 * composes its `MapTypeStyle[]` array.
 */
const DARK_MAP_STYLE: string[] = [
    'element:geometry|color:0x242f3e',
    'element:labels.text.fill|color:0x746855',
    'element:labels.text.stroke|color:0x242f3e',
    'feature:administrative.locality|element:labels.text.fill|color:0xd59563',
    'feature:poi|element:labels.text.fill|color:0xd59563',
    'feature:poi.park|element:geometry|color:0x263c3f',
    'feature:poi.park|element:labels.text.fill|color:0x6b9a76',
    'feature:road|element:geometry|color:0x38414e',
    'feature:road|element:geometry.stroke|color:0x212a37',
    'feature:road|element:labels.text.fill|color:0x9ca5b3',
    'feature:road.highway|element:geometry|color:0x746855',
    'feature:road.highway|element:geometry.stroke|color:0x1f2835',
    'feature:road.highway|element:labels.text.fill|color:0xf3d19c',
    'feature:transit|element:geometry|color:0x2f3948',
    'feature:transit.station|element:labels.text.fill|color:0xd59563',
    'feature:water|element:geometry|color:0x17263c',
    'feature:water|element:labels.text.fill|color:0x515c6d',
    'feature:water|element:labels.text.stroke|color:0x17263c',
];

/**
 * Google polyline encoding (Encoded Polyline Algorithm Format) for an
 * array of [lat, lng] pairs. Used to compress a route into the
 * `path=enc:…` parameter of the Static Maps API so the URL stays under
 * the ~8 KB limit for routes with many vertices.
 */
function encodePolylineSegment(value: number): string {
    let scaled = Math.round(value * 1e5);
    scaled <<= 1;
    if (scaled < 0) {
        scaled = ~scaled;
    }
    let out = '';
    while (scaled >= 0x20) {
        out += String.fromCharCode((0x20 | (scaled & 0x1f)) + 63);
        scaled >>= 5;
    }
    out += String.fromCharCode(scaled + 63);
    return out;
}

export function encodePolyline(points: Array<[number, number]>): string {
    let prevLat = 0;
    let prevLng = 0;
    let out = '';
    for (const [lat, lng] of points) {
        out += encodePolylineSegment(lat - prevLat);
        out += encodePolylineSegment(lng - prevLng);
        prevLat = lat;
        prevLng = lng;
    }
    return out;
}

/**
 * Uniformly downsample a polyline to at most `maxPoints` vertices,
 * always preserving the first and last point so the route still
 * starts at the origin and ends at the destination. The Static Maps
 * API caps URL length at ~8 KB; a real-world route (e.g. Cali →
 * Bogotá ≈ 5 400 points) encodes to ~22 KB and silently fails, so
 * the caller can't ship the raw geometry. ~150 points renders a
 * visually faithful polyline at a 480×220 thumbnail size.
 */
function downsamplePolyline(
    points: Array<[number, number]>,
    maxPoints: number,
): Array<[number, number]> {
    if (points.length <= maxPoints) {
        return points;
    }
    const out: Array<[number, number]> = [];
    const step = (points.length - 1) / (maxPoints - 1);
    for (let i = 0; i < maxPoints - 1; i++) {
        out.push(points[Math.round(i * step)]);
    }
    out.push(points[points.length - 1]);
    return out;
}

/**
 * Build a Maps Static API URL that frames a single trip: green `A`
 * marker at the origin, red `B` marker at the destination, and the
 * route polyline between them. When `geometry` is omitted (or empty)
 * the call falls back to a straight `path` between the two markers so
 * the image still conveys the origin → destination relationship while
 * the cached route is being fetched.
 */
export function staticRouteMapUrl({
    origin,
    destination,
    geometry,
    theme = 'light',
    width = 600,
    height = 300,
    scale = 2,
}: StaticRouteMapOptions): string {
    const params = new URLSearchParams();
    params.append('size', `${width}x${height}`);
    params.append('scale', String(scale));
    params.append('markers', `color:green|label:A|${origin.lat},${origin.lng}`);
    params.append(
        'markers',
        `color:red|label:B|${destination.lat},${destination.lng}`,
    );

    if (theme === 'dark') {
        for (const rule of DARK_MAP_STYLE) {
            params.append('style', rule);
        }
    }

    if (geometry && geometry.length >= 2) {
        // GeoJSON LineString stores [lng, lat]; the polyline encoder
        // expects [lat, lng].
        const latLngs: Array<[number, number]> = geometry
            .filter((p) => Array.isArray(p) && p.length >= 2)
            .map((p) => [p[1], p[0]]);
        const simplified = downsamplePolyline(latLngs, 150);
        const encoded = encodePolyline(simplified);
        params.append('path', `color:0x4285F4ff|weight:4|enc:${encoded}`);
    } else {
        params.append(
            'path',
            `color:0x4285F4ff|weight:4|${origin.lat},${origin.lng}|${destination.lat},${destination.lng}`,
        );
    }

    params.append('key', GOOGLE_MAPS_BROWSER_KEY);
    return `https://maps.googleapis.com/maps/api/staticmap?${params.toString()}`;
}
