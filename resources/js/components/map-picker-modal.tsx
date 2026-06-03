import {
    AdvancedMarker,
    ColorScheme,
    Map as GoogleMap,
    type MapMouseEvent,
    useMap,
    useMapsLibrary,
} from '@vis.gl/react-google-maps';
import { Loader2, MapPin } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { MapDisabledPlaceholder } from '@/components/map-disabled-placeholder';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAppearance } from '@/hooks/use-appearance';
import { dlog } from '@/lib/debug-log';
import { reverseGeocode } from '@/lib/google-geocoding';
import {
    BOGOTA_FALLBACK,
    GOOGLE_MAPS_MAP_ID,
    MAPS_ENABLED,
} from '@/lib/google-maps';
import { cn } from '@/lib/utils';

const REVERSE_DEBOUNCE_MS = 600;

type LatLng = { lat: number; lng: number };

interface MapPickerModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialCenter: LatLng | null;
    initialPin: LatLng | null;
    /**
     * Pre-populates the editable address field inside the modal — the
     * operator's existing draft, which they can refine before confirm.
     * Whatever they leave in the field at confirm time becomes the
     * persisted address text for the form.
     */
    addressHint: string;
    municipalityHint: string | null;
    /**
     * Returns the chosen coordinates, the address text the operator
     * typed inside the modal, and — when Google could reverse-geocode
     * the pin — the city name detected via the address components. The
     * form uses `coords` + `address` together as a single intentional
     * commit, and `placeName` to fuzzy-match against the DANE catalog
     * so the LocationField chip can auto-populate when the operator
     * dropped a pin without selecting a municipality first.
     */
    onConfirm: (data: {
        coords: LatLng;
        address: string;
        placeName: string | null;
    }) => void;
    /** Used as a debug-log channel suffix to distinguish origin vs destination. */
    instanceLabel?: string;
}

/**
 * Modal wrapper. The body is rendered only while `open` is true so that
 * each opening remounts the inner component — pin / hint state are
 * initialized at mount time from props rather than reset via an effect,
 * which keeps React Compiler happy and avoids cascading rerenders.
 *
 * Relies on the surrounding `<APIProvider>` added in `service-form.tsx`
 * for the Google Maps JS load; React context flows to this subtree even
 * though the dialog content is portaled in the DOM.
 */
export default function MapPickerModal({
    open,
    onOpenChange,
    initialCenter,
    initialPin,
    addressHint,
    municipalityHint,
    onConfirm,
    instanceLabel,
}: MapPickerModalProps) {
    const channel = `map-picker:${instanceLabel ?? 'default'}`;
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                dlog(channel, next ? 'open' : 'close', {
                    addressHint,
                    municipalityHint,
                    initialCenter,
                    initialPin,
                });
                onOpenChange(next);
            }}
        >
            <DialogContent
                className={cn(
                    'sm:max-w-3xl',
                    'flex flex-col gap-3 p-0',
                    'h-[90vh] sm:h-[80vh]',
                )}
            >
                {open && !MAPS_ENABLED ? (
                    <MapDisabledPlaceholder label="El selector de mapa está desactivado en este entorno." />
                ) : open ? (
                    <MapPickerBody
                        channel={channel}
                        initialCenter={initialCenter}
                        initialPin={initialPin}
                        addressHint={addressHint}
                        municipalityHint={municipalityHint}
                        onCancel={() => {
                            dlog(channel, 'cancel');
                            onOpenChange(false);
                        }}
                        onConfirm={(data) => {
                            dlog(channel, 'confirm', data);
                            onConfirm(data);
                        }}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

interface BodyProps {
    channel: string;
    initialCenter: LatLng | null;
    initialPin: LatLng | null;
    addressHint: string;
    municipalityHint: string | null;
    onCancel: () => void;
    onConfirm: (data: {
        coords: LatLng;
        address: string;
        placeName: string | null;
    }) => void;
}

function MapPickerBody({
    channel,
    initialCenter,
    initialPin,
    addressHint,
    municipalityHint,
    onCancel,
    onConfirm,
}: BodyProps) {
    const [pin, setPin] = useState<LatLng | null>(initialPin);
    const [hint, setHint] = useState<string>('—');
    const [hintLoading, setHintLoading] = useState(false);
    const { resolvedAppearance } = useAppearance();
    // Editable mirror of the form's address input. Pre-populated with
    // whatever the operator already had typed; confirming the modal
    // pushes this value back as the canonical address text.
    const [addressDraft, setAddressDraft] = useState<string>(addressHint);
    // Last reverse-geocoded city name, kept so we can hand it back on
    // confirm — the form uses it to auto-populate the city chip when the
    // operator hasn't selected one.
    const [lastCityName, setLastCityName] = useState<string | null>(null);

    // Ensure the Geocoding library is loaded so reverseGeocode's
    // `new google.maps.Geocoder()` resolves once the user drops a pin.
    useMapsLibrary('geocoding');

    const reverseTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // Geocoder has no AbortController; a monotonic sequence number lets a
    // newer reverse-geocode supersede a slower in-flight one.
    const reverseSeqRef = useRef(0);

    const center = initialPin ?? initialCenter ?? BOGOTA_FALLBACK;
    const initialZoom = initialPin ? 16 : initialCenter ? 14 : 11;

    useEffect(() => {
        return () => {
            if (reverseTimerRef.current) {
                clearTimeout(reverseTimerRef.current);
                reverseTimerRef.current = null;
            }
            reverseSeqRef.current += 1;
        };
    }, []);

    const scheduleReverse = (next: LatLng) => {
        if (reverseTimerRef.current) {
            clearTimeout(reverseTimerRef.current);
        }
        setHintLoading(true);
        const seq = ++reverseSeqRef.current;
        dlog(channel, 'reverse scheduled', {
            lat: next.lat,
            lng: next.lng,
            debounce_ms: REVERSE_DEBOUNCE_MS,
        });
        reverseTimerRef.current = setTimeout(() => {
            reverseGeocode(next.lat, next.lng)
                .then((res) => {
                    if (seq !== reverseSeqRef.current) return;
                    dlog(channel, 'reverse hint', {
                        text: res.displayText,
                        placeName: res.cityName,
                    });
                    setHint(res.displayText);
                    setLastCityName(res.cityName);
                    setHintLoading(false);
                })
                .catch((err) => {
                    if (seq !== reverseSeqRef.current) return;
                    dlog(channel, 'reverse error', {
                        error: (err as Error).message,
                    });
                    setHint('—');
                    setLastCityName(null);
                    setHintLoading(false);
                });
        }, REVERSE_DEBOUNCE_MS);
    };

    const handlePinChange = (next: LatLng, source: 'click' | 'drag') => {
        dlog(channel, `pin ${source}`, { lat: next.lat, lng: next.lng });
        setPin(next);
        scheduleReverse(next);
    };

    const confirmDisabled = pin === null;

    return (
        <>
            <DialogHeader className="px-6 pt-6">
                <DialogTitle>Marcar ubicación en el mapa</DialogTitle>
                <DialogDescription>
                    Coloca el pin donde el conductor debe parar y escribe abajo
                    la dirección que verá en su panel.
                    {municipalityHint && (
                        <span className="mt-1 block">
                            <strong>Ciudad:</strong> {municipalityHint}
                        </span>
                    )}
                </DialogDescription>
            </DialogHeader>

            <div className="space-y-1 px-6">
                <Label htmlFor="map-picker-address">
                    Dirección visible para el conductor
                </Label>
                <div className="flex items-stretch gap-2">
                    <Input
                        id="map-picker-address"
                        value={addressDraft}
                        onChange={(e) => setAddressDraft(e.target.value)}
                        placeholder="Ej: Calle 41A Sur #83-17, Casa de don Pepe…"
                        autoComplete="off"
                        spellCheck={false}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!hint || hint === '—'}
                        onClick={() => setAddressDraft(hint)}
                        title="Usar la dirección que sugiere el mapa como texto"
                        className="shrink-0"
                    >
                        Usar sugerencia
                    </Button>
                </div>
            </div>

            <div className="relative flex-1 px-6">
                <div className="h-full overflow-hidden rounded-md border">
                    <GoogleMap
                        mapId={GOOGLE_MAPS_MAP_ID}
                        defaultCenter={center}
                        defaultZoom={initialZoom}
                        gestureHandling="greedy"
                        disableDefaultUI={false}
                        clickableIcons={false}
                        colorScheme={
                            resolvedAppearance === 'dark'
                                ? ColorScheme.DARK
                                : ColorScheme.LIGHT
                        }
                        className="size-full"
                        onClick={(ev: MapMouseEvent) => {
                            const ll = ev.detail.latLng;
                            if (ll) {
                                handlePinChange(
                                    { lat: ll.lat, lng: ll.lng },
                                    'click',
                                );
                            }
                        }}
                    >
                        {pin && (
                            <AdvancedMarker
                                position={pin}
                                draggable
                                onDragEnd={(ev) => {
                                    const ll = ev.latLng;
                                    if (ll) {
                                        handlePinChange(
                                            { lat: ll.lat(), lng: ll.lng() },
                                            'drag',
                                        );
                                    }
                                }}
                            />
                        )}
                        {pin && <RecenterMap target={pin} />}
                    </GoogleMap>
                </div>
            </div>

            <div className="space-y-1 px-6 text-xs">
                <p className="flex items-center gap-1 text-muted-foreground">
                    <MapPin className="size-3" />
                    {pin ? (
                        <span>
                            Coordenadas:{' '}
                            <code>
                                {pin.lat.toFixed(7)},{pin.lng.toFixed(7)}
                            </code>
                        </span>
                    ) : (
                        <span>Haz click en el mapa para colocar el pin.</span>
                    )}
                </p>
                <p className="flex items-center gap-1 text-muted-foreground">
                    <span className="font-medium">Cerca de:</span>
                    {hintLoading && <Loader2 className="size-3 animate-spin" />}
                    <span className="truncate">{hint}</span>
                </p>
            </div>

            <DialogFooter className="gap-2 border-t px-6 pt-3 pb-6 sm:gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancelar
                </Button>
                <Button
                    type="button"
                    disabled={confirmDisabled}
                    onClick={() => {
                        if (pin) {
                            onConfirm({
                                coords: pin,
                                address: addressDraft.trim(),
                                placeName: lastCityName,
                            });
                        }
                    }}
                >
                    Confirmar
                </Button>
            </DialogFooter>
        </>
    );
}

/**
 * Pans the map toward the freshly-placed pin without zooming out — if we
 * were below zoom 14 (e.g. the operator just dropped the first pin from
 * a country-level view) we bump up to 14 first.
 */
function RecenterMap({ target }: { target: LatLng }) {
    const map = useMap();
    useEffect(() => {
        if (!map) return;
        const currentZoom = map.getZoom() ?? 14;
        if (currentZoom < 14) {
            map.setZoom(14);
        }
        map.panTo(target);
    }, [map, target]);
    return null;
}
