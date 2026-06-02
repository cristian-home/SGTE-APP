import { Head, Link, router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Can } from '@/components/can';
import LocationStaticMap from '@/components/services/location-static-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Permission } from '@/enums/Permission';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import { formatTimestampInViewerTz } from '@/lib/datetime';
import vehicles from '@/routes/vehicles';
import type { BreadcrumbItem } from '@/types';

interface Location {
    id: number;
    vehicle_id: number;
    service_id: number | null;
    recorded_at: string | null;
    latitude: string;
    longitude: string;
    accuracy: string | null;
    is_manual: boolean;
    captured_by: number | null;
    vehicle?: {
        id: number;
        plate: string;
        brand: string | null;
        line: string | null;
    } | null;
    service?: { id: number; service_date: string | null } | null;
    captured_by_user?: { id: number; name: string; email: string } | null;
}

export default function VehicleLocationShow({
    vehicleLocation,
}: {
    vehicleLocation: Location;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'GPS', href: '#' },
        { title: 'Ubicaciones', href: '/vehicle-locations' },
        {
            title: `#${vehicleLocation.id}`,
            href: `/vehicle-locations/${vehicleLocation.id}`,
        },
    ];

    const recordedAt =
        formatTimestampInViewerTz(vehicleLocation.recorded_at) || '—';
    const coords = `${vehicleLocation.latitude},${vehicleLocation.longitude}`;
    const mapsUrl = `https://www.google.com/maps?q=${coords}`;
    const vehicleSubtitle = [
        vehicleLocation.vehicle?.brand,
        vehicleLocation.vehicle?.line,
    ]
        .filter(Boolean)
        .join(' ');

    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    async function handleCopy() {
        const ok = await copy(coords);
        if (ok) {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        }
    }

    function handleDelete() {
        if (confirm('¿Eliminar esta ubicación? Esta acción es auditable.')) {
            router.delete(`/vehicle-locations/${vehicleLocation.id}`);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Ubicación #${vehicleLocation.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2">
                                <span className="font-mono">
                                    {vehicleLocation.vehicle?.plate ??
                                        'Vehículo —'}
                                </span>
                                {vehicleLocation.is_manual ? (
                                    <Badge variant="outline">Manual</Badge>
                                ) : (
                                    <Badge>GPS</Badge>
                                )}
                            </CardTitle>
                            <div className="text-sm text-muted-foreground">
                                {vehicleSubtitle && (
                                    <span>{vehicleSubtitle} · </span>
                                )}
                                {recordedAt}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {vehicleLocation.vehicle && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={
                                            vehicles.show(
                                                vehicleLocation.vehicle.id,
                                            ).url
                                        }
                                    >
                                        Ver vehículo
                                    </Link>
                                </Button>
                            )}
                            <Can
                                permission={Permission.DELETE_VEHICLE_LOCATIONS}
                            >
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={handleDelete}
                                >
                                    <Trash2 className="mr-2 size-4" />
                                    Eliminar
                                </Button>
                            </Can>
                        </div>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-sm">Mapa</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <LocationStaticMap
                                coordinates={coords}
                                label="Ubicación"
                                height={480}
                                className="h-auto w-full"
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm">Detalles</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="space-y-2">
                                <div className="text-xs text-muted-foreground">
                                    Coordenadas
                                </div>
                                <div className="font-mono text-sm">
                                    {vehicleLocation.latitude}
                                </div>
                                <div className="font-mono text-sm">
                                    {vehicleLocation.longitude}
                                </div>
                                <div className="flex flex-wrap gap-2 pt-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleCopy}
                                    >
                                        {copied ? (
                                            <Check className="mr-2 size-4" />
                                        ) : (
                                            <Copy className="mr-2 size-4" />
                                        )}
                                        {copied ? 'Copiado' : 'Copiar'}
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={mapsUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <ExternalLink className="mr-2 size-4" />
                                            Abrir en Maps
                                        </a>
                                    </Button>
                                </div>
                            </div>

                            <Separator />

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Precisión
                                    </div>
                                    <div className="font-mono">
                                        {vehicleLocation.accuracy
                                            ? `± ${vehicleLocation.accuracy} m`
                                            : '—'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Origen
                                    </div>
                                    <div>
                                        {vehicleLocation.is_manual
                                            ? 'Manual'
                                            : 'GPS'}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Servicio
                                    </div>
                                    <div>
                                        {vehicleLocation.service ? (
                                            <Link
                                                href={`/services/${vehicleLocation.service.id}`}
                                                className="text-primary hover:underline"
                                            >
                                                #{vehicleLocation.service.id}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Registrado por
                                    </div>
                                    <div>
                                        {vehicleLocation.captured_by_user
                                            ?.name ?? 'Sistema'}
                                    </div>
                                    {vehicleLocation.captured_by_user
                                        ?.email && (
                                        <div className="text-xs text-muted-foreground">
                                            {
                                                vehicleLocation.captured_by_user
                                                    .email
                                            }
                                        </div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
