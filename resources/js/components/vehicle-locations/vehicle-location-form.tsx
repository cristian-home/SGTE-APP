import { ChevronDown, Crosshair, MapPin } from 'lucide-react';
import { useState } from 'react';
import FieldFooter from '@/components/field-footer';
import MapPickerModal from '@/components/map-picker-modal';
import { MapsScope } from '@/components/maps-scope';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import VehicleCombobox, {
    type VehicleOption,
} from '@/components/vehicles/vehicle-combobox';
import { BOGOTA_FALLBACK } from '@/lib/google-maps';
import { cn } from '@/lib/utils';

export interface VehicleLocationFormData {
    vehicle_id: number | '';
    service_id: number | null | '';
    recorded_at: string;
    latitude: string;
    longitude: string;
    accuracy: string;
    is_manual: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

interface Props {
    data: VehicleLocationFormData;
    setData: <K extends keyof VehicleLocationFormData>(
        key: K,
        value: VehicleLocationFormData[K],
    ) => void;
    errors: Partial<Record<keyof VehicleLocationFormData, string>>;
    vehicles: VehicleOption[];
}

function parseCoord(value: string): number | null {
    if (value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

export function VehicleLocationForm({
    data,
    setData,
    errors,
    vehicles,
}: Props) {
    const [mapOpen, setMapOpen] = useState(false);
    const [geoLoading, setGeoLoading] = useState(false);
    const [geoError, setGeoError] = useState<string | null>(null);
    const [showManualFields, setShowManualFields] = useState(false);

    const lat = parseCoord(data.latitude);
    const lng = parseCoord(data.longitude);
    const hasCoords = lat !== null && lng !== null;
    const pin = hasCoords ? { lat: lat!, lng: lng! } : null;

    function applyCoords(
        coords: { lat: number; lng: number },
        accuracy?: number,
    ) {
        setData('latitude', String(coords.lat));
        setData('longitude', String(coords.lng));
        if (accuracy !== undefined) {
            setData('accuracy', String(Math.round(accuracy)));
        }
        setGeoError(null);
    }

    function captureCurrentPosition() {
        if (!navigator.geolocation) {
            setGeoError('El navegador no soporta geolocalización.');
            return;
        }
        setGeoLoading(true);
        setGeoError(null);
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                applyCoords(
                    { lat: pos.coords.latitude, lng: pos.coords.longitude },
                    pos.coords.accuracy,
                );
                setData('is_manual', false);
                setGeoLoading(false);
            },
            (err) => {
                setGeoError(
                    err.code === err.PERMISSION_DENIED
                        ? 'Permiso de ubicación denegado.'
                        : 'No se pudo obtener la ubicación actual.',
                );
                setGeoLoading(false);
            },
            { enableHighAccuracy: true, timeout: 15_000, maximumAge: 0 },
        );
    }

    return (
        <MapsScope>
            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-1 md:col-span-2">
                    <Label htmlFor="vehicle_id">Vehículo *</Label>
                    <VehicleCombobox
                        id="vehicle_id"
                        vehicles={vehicles}
                        value={data.vehicle_id === '' ? null : data.vehicle_id}
                        onChange={(value) => setData('vehicle_id', value ?? '')}
                        invalid={Boolean(errors.vehicle_id)}
                    />
                    <FieldFooter error={errors.vehicle_id} />
                </div>

                <div className="space-y-1 md:col-span-2">
                    <Label htmlFor="recorded_at">Fecha/Hora *</Label>
                    <Input
                        id="recorded_at"
                        type="datetime-local"
                        value={data.recorded_at}
                        onChange={(e) => setData('recorded_at', e.target.value)}
                        aria-invalid={Boolean(errors.recorded_at)}
                    />
                    <FieldFooter error={errors.recorded_at} />
                </div>

                <div className="space-y-2 md:col-span-2">
                    <Label>Ubicación *</Label>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setData('is_manual', true);
                                setMapOpen(true);
                            }}
                        >
                            <MapPin className="size-4" />
                            {hasCoords ? 'Cambiar en mapa' : 'Marcar en mapa'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={captureCurrentPosition}
                            disabled={geoLoading}
                        >
                            <Crosshair className="size-4" />
                            {geoLoading
                                ? 'Obteniendo…'
                                : 'Usar mi ubicación actual'}
                        </Button>
                    </div>

                    {hasCoords && (
                        <p className="text-sm text-muted-foreground">
                            <span className="font-mono">
                                {lat!.toFixed(6)}, {lng!.toFixed(6)}
                            </span>
                            {data.accuracy && (
                                <>
                                    {' · '}
                                    precisión {data.accuracy} m
                                </>
                            )}
                        </p>
                    )}
                    {geoError && (
                        <p className="text-sm text-destructive">{geoError}</p>
                    )}
                    <FieldFooter error={errors.latitude} />
                    <FieldFooter error={errors.longitude} />
                </div>

                <Collapsible
                    open={showManualFields}
                    onOpenChange={setShowManualFields}
                    className="md:col-span-2"
                >
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                        >
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    showManualFields && 'rotate-180',
                                )}
                            />
                            Editar coordenadas manualmente
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className="mt-2 grid gap-4 md:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="latitude">Latitud</Label>
                            <Input
                                id="latitude"
                                type="number"
                                step="any"
                                value={data.latitude}
                                onChange={(e) => {
                                    setData('latitude', e.target.value);
                                    setData('is_manual', true);
                                }}
                                aria-invalid={Boolean(errors.latitude)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="longitude">Longitud</Label>
                            <Input
                                id="longitude"
                                type="number"
                                step="any"
                                value={data.longitude}
                                onChange={(e) => {
                                    setData('longitude', e.target.value);
                                    setData('is_manual', true);
                                }}
                                aria-invalid={Boolean(errors.longitude)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="accuracy">Precisión (m)</Label>
                            <Input
                                id="accuracy"
                                type="number"
                                step="any"
                                value={data.accuracy}
                                onChange={(e) =>
                                    setData('accuracy', e.target.value)
                                }
                                aria-invalid={Boolean(errors.accuracy)}
                            />
                            <FieldFooter error={errors.accuracy} />
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>

            {mapOpen && (
                <MapPickerModal
                    open={mapOpen}
                    onOpenChange={setMapOpen}
                    initialCenter={pin ?? BOGOTA_FALLBACK}
                    initialPin={pin}
                    addressHint=""
                    municipalityHint={null}
                    onConfirm={({ coords }) => {
                        applyCoords(coords);
                        setData('is_manual', true);
                        // Clear accuracy: a manual pin has no GPS accuracy
                        // reading; the form treats empty as "unknown".
                        setData('accuracy', '');
                        setMapOpen(false);
                    }}
                    instanceLabel="vehicle-location"
                />
            )}
        </MapsScope>
    );
}
