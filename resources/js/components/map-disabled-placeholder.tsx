import { MapPinned } from 'lucide-react';

/**
 * Shown in place of any Google Map (dynamic or static preview) when
 * `MAPS_ENABLED` is false — i.e. local development. Renders nothing that
 * touches Google, so HMR/StrictMode remounts in dev cost zero API calls.
 */
export function MapDisabledPlaceholder({
    className,
    label = 'Mapa desactivado en este entorno',
}: {
    className?: string;
    label?: string;
}) {
    return (
        <div
            className={
                'flex size-full min-h-32 flex-col items-center justify-center gap-2 bg-muted/30 p-4 text-center text-muted-foreground ' +
                (className ?? '')
            }
        >
            <MapPinned className="size-6" aria-hidden />
            <p className="text-xs">{label}</p>
        </div>
    );
}
