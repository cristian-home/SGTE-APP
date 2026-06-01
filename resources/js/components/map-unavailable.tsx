import { MapPinOff } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Fallback shown when a Google Maps widget throws (e.g. a revoked or
 * invalid API key makes the JS API fail to authenticate, after which
 * `<AdvancedMarker>` mounts throw synchronously during commit). Rendered
 * by an <ErrorBoundary> so the surrounding page and SPA navigation stay
 * intact instead of the whole React tree unmounting.
 */
export function MapUnavailable({ reset }: { reset: () => void }) {
    return (
        <div className="flex size-full min-h-40 flex-col items-center justify-center gap-3 bg-muted/30 p-6 text-center">
            <MapPinOff className="size-6 text-muted-foreground" aria-hidden />
            <div className="space-y-1">
                <p className="text-sm font-medium">
                    No se pudo cargar el mapa.
                </p>
                <p className="text-sm text-muted-foreground">
                    Revisa la configuración de Google Maps. El resto de la
                    página sigue disponible.
                </p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={reset}>
                Reintentar
            </Button>
        </div>
    );
}
