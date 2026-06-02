import { APIProvider } from '@vis.gl/react-google-maps';
import { type ReactNode } from 'react';
import { GOOGLE_MAPS_BROWSER_KEY, MAPS_ENABLED } from '@/lib/google-maps';

/**
 * Mounts the Google Maps `<APIProvider>` (which loads the Maps JS SDK for
 * geocoding/autocomplete/pickers) only when maps are enabled. With
 * `VITE_GOOGLE_MAPS_ENABLED=false` (local dev) it renders children plain,
 * so `useMapsLibrary(...)` stays null and address fields fall back to
 * manual entry — no Google script load, no billed calls.
 */
export function MapsScope({ children }: { children: ReactNode }) {
    if (!MAPS_ENABLED) {
        return <>{children}</>;
    }
    return (
        <APIProvider apiKey={GOOGLE_MAPS_BROWSER_KEY}>{children}</APIProvider>
    );
}
