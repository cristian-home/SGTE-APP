import { dperf, dwarn } from '@/lib/debug-log';

/**
 * Geocoding results (placeId→coords, reverse lookups) may be cached up to
 * 30 days per Google Maps Platform Terms 3.2.3(b). We cache successful
 * lookups in localStorage so repeated views of the same address don't
 * re-bill. (Autocomplete is NOT cached — it's already session-token billed.)
 */
const GEOCODE_CACHE_TTL_MS = 30 * 24 * 60 * 60 * 1000;

function readGeocodeCache<T>(key: string): T | null {
    try {
        const raw = localStorage.getItem(key);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw) as { v: T; exp: number };
        if (typeof parsed?.exp !== 'number' || parsed.exp < Date.now()) {
            localStorage.removeItem(key);
            return null;
        }
        return parsed.v;
    } catch {
        return null;
    }
}

function writeGeocodeCache<T>(key: string, value: T): void {
    try {
        localStorage.setItem(
            key,
            JSON.stringify({
                v: value,
                exp: Date.now() + GEOCODE_CACHE_TTL_MS,
            }),
        );
    } catch {
        // localStorage unavailable / quota exceeded — caching is best-effort.
    }
}

/**
 * Typed helpers around the Google Maps JavaScript SDK for the SGTE
 * address pipeline. The SDK objects (`google.maps.places.*`,
 * `google.maps.Geocoder`) are loaded by `@vis.gl/react-google-maps`'s
 * `<APIProvider>` + `useMapsLibrary(...)`; every function here assumes
 * the relevant library is already loaded and is only called from
 * components that gated on it.
 *
 * Pipeline:
 *
 * - `fetchAutocomplete` powers the typeahead via the Places API (New)
 *   `AutocompleteSuggestion.fetchAutocompleteSuggestions`, restricted to
 *   Colombia and biased toward the selected municipality centroid.
 * - `resolvePlace` turns a picked `place_id` into a `ResolvedPlace` via
 *   `Geocoder.geocode({ placeId })`. Going through the Geocoder (instead
 *   of Place Details) yields the Google `location_type` accuracy value
 *   that SGTE persists — Place Details does not expose it.
 * - `reverseGeocode` powers the manual map-picker "Cerca de:" hint.
 *
 * Session billing: a per-typeahead `AutocompleteSessionToken` groups the
 * keystroke autocomplete calls (the expensive part) into one billable
 * session. The place-details resolve is a separate Geocoding API call,
 * which is the trade-off for getting the `location_type` accuracy.
 */

export interface PlaceSuggestion {
    /** Google Place ID — the durable reference passed to `resolvePlace`. */
    placeId: string;
    /** Primary line of the prediction (usually the street address). */
    primaryText: string;
    /** Secondary line (usually the locality / region context). */
    secondaryText: string;
}

export interface ResolvedPlace {
    lat: number;
    lng: number;
    placeId: string;
    formattedAddress: string;
    /** Google Geocoder `location_type` (ROOFTOP / RANGE_INTERPOLATED / …). */
    locationType: string;
    /** Detected city name (locality), used to auto-populate the city chip. */
    placeName: string | null;
}

export interface ReverseGeocodeResult {
    /** Human-readable address for the "Cerca de:" hint. */
    displayText: string;
    /** Detected city name, used to auto-populate the city chip. */
    cityName: string | null;
}

export type AccuracyTone = 'green' | 'yellow' | 'gray';

interface AutocompleteOptions {
    sessionToken: google.maps.places.AutocompleteSessionToken;
    /** Circle around the selected municipality centroid to bias results. */
    locationBias?: google.maps.places.LocationBias;
    signal?: AbortSignal;
}

/**
 * Create a fresh autocomplete session token — one per typeahead session,
 * created when the operator starts typing.
 */
export function createSessionToken(): google.maps.places.AutocompleteSessionToken {
    return new google.maps.places.AutocompleteSessionToken();
}

/**
 * Round a coordinate to 7 decimal places (~11mm) — the precision SGTE
 * persists in the `*_coordinates` columns.
 */
function round7(value: number): number {
    return Math.round(value * 1e7) / 1e7;
}

/**
 * Extract a city name from a Geocoder result's address components,
 * preferring `locality` and falling back to
 * `administrative_area_level_2`.
 */
function cityFromComponents(
    components: google.maps.GeocoderAddressComponent[] | undefined,
): string | null {
    if (!components) {
        return null;
    }
    const locality = components.find((c) => c.types.includes('locality'));
    if (locality) {
        return locality.long_name;
    }
    const admin2 = components.find((c) =>
        c.types.includes('administrative_area_level_2'),
    );
    return admin2?.long_name ?? null;
}

/**
 * Fetch address autocomplete predictions, restricted to Colombia and
 * (optionally) biased toward a municipality centroid. Returns an empty
 * list when the request was superseded (`signal` aborted).
 */
export async function fetchAutocomplete(
    input: string,
    { sessionToken, locationBias, signal }: AutocompleteOptions,
): Promise<PlaceSuggestion[]> {
    const done = dperf('google-geocoding', 'autocomplete', {
        input,
        biased: locationBias !== undefined,
    });

    const request: google.maps.places.AutocompleteRequest = {
        input,
        includedRegionCodes: ['co'],
        language: 'es',
        sessionToken,
    };
    if (locationBias) {
        request.locationBias = locationBias;
    }

    try {
        const { suggestions } =
            await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(
                request,
            );
        if (signal?.aborted) {
            done({ aborted: true });
            return [];
        }

        const predictions = suggestions
            .map((s) => s.placePrediction)
            .filter((p): p is google.maps.places.PlacePrediction => p !== null);

        done({ results: predictions.length });

        return predictions.map((p) => ({
            placeId: p.placeId,
            primaryText: p.mainText?.text ?? p.text.text,
            secondaryText: p.secondaryText?.text ?? '',
        }));
    } catch (err) {
        dwarn('google-geocoding', 'autocomplete error', {
            input,
            error: (err as Error).message,
        });
        throw err;
    }
}

/**
 * Resolve a picked `place_id` into coordinates + accuracy via the
 * Geocoder. Returns null when Google has no result for the id.
 */
export async function resolvePlace(
    placeId: string,
): Promise<ResolvedPlace | null> {
    const done = dperf('google-geocoding', 'resolvePlace', { placeId });

    const cacheKey = `gmcache:resolve:${placeId}`;
    const cached = readGeocodeCache<ResolvedPlace>(cacheKey);
    if (cached) {
        done({ outcome: 'cache' });
        return cached;
    }

    const geocoder = new google.maps.Geocoder();

    try {
        const { results } = await geocoder.geocode({ placeId });
        const result = results[0] ?? null;
        if (!result) {
            done({ outcome: 'no-result' });
            return null;
        }

        const location = result.geometry.location;
        done({
            outcome: 'resolved',
            locationType: result.geometry.location_type,
        });

        const resolved: ResolvedPlace = {
            lat: round7(location.lat()),
            lng: round7(location.lng()),
            placeId,
            formattedAddress: result.formatted_address,
            locationType: String(result.geometry.location_type),
            placeName: cityFromComponents(result.address_components),
        };
        writeGeocodeCache(cacheKey, resolved);
        return resolved;
    } catch (err) {
        dwarn('google-geocoding', 'resolvePlace error', {
            placeId,
            error: (err as Error).message,
        });
        throw err;
    }
}

/**
 * Reverse geocode a coordinate into a display string + detected city
 * name. Used for the manual map-picker "Cerca de:" hint; never persisted
 * as accuracy. Returns a neutral placeholder when nothing matches.
 */
export async function reverseGeocode(
    lat: number,
    lng: number,
): Promise<ReverseGeocodeResult> {
    const done = dperf('google-geocoding', 'reverseGeocode', { lat, lng });

    const cacheKey = `gmcache:reverse:${round7(lat)},${round7(lng)}`;
    const cached = readGeocodeCache<ReverseGeocodeResult>(cacheKey);
    if (cached) {
        done({ outcome: 'cache' });
        return cached;
    }

    const geocoder = new google.maps.Geocoder();

    try {
        const { results } = await geocoder.geocode({
            location: { lat, lng },
        });
        const result = results[0] ?? null;
        if (!result) {
            done({ outcome: 'no-result' });
            return { displayText: '—', cityName: null };
        }

        done({ outcome: 'resolved' });
        const resolved: ReverseGeocodeResult = {
            displayText: result.formatted_address,
            cityName: cityFromComponents(result.address_components),
        };
        writeGeocodeCache(cacheKey, resolved);
        return resolved;
    } catch (err) {
        dwarn('google-geocoding', 'reverseGeocode error', {
            lat,
            lng,
            error: (err as Error).message,
        });
        throw err;
    }
}

/**
 * Map a Google `location_type` accuracy value to the confidence-badge
 * tone rendered under the address input.
 */
export function locationTypeTone(locationType: string): AccuracyTone {
    if (locationType === 'ROOFTOP') {
        return 'green';
    }
    if (
        locationType === 'RANGE_INTERPOLATED' ||
        locationType === 'GEOMETRIC_CENTER'
    ) {
        return 'yellow';
    }
    return 'gray';
}
