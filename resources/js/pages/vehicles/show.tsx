import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, Pencil, Truck, User } from 'lucide-react';
import { useState } from 'react';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import VehicleDialog from '@/components/vehicles/vehicle-dialog';
import { VehicleDocumentPills } from '@/components/vehicles/vehicle-document-pills';
import {
    type ThirdPartyOption,
    type VehicleTypeOption,
} from '@/components/vehicles/vehicle-form';
import AppLayout from '@/layouts/app-layout';
import services from '@/routes/services';
import vehicles from '@/routes/vehicles';

import type { BreadcrumbItem } from '@/types';
import type { Service, Vehicle } from '@/types/models';

// Local shape — does NOT extend Vehicle because the global Vehicle
// type uses `relation?: T` (undefined-only), while the show payload
// can return `null` for absent relationships. Picking the relevant
// scalar fields keeps both compatible without fighting the typings.
type ShowVehicle = Pick<
    Vehicle,
    | 'id'
    | 'internal_code'
    | 'plate'
    | 'mobile_number'
    | 'brand'
    | 'line'
    | 'model_year'
    | 'vehicle_type_id'
    | 'engine_number'
    | 'chassis_number'
    | 'capacity'
    | 'municipality_id'
    | 'is_third_party'
    | 'third_party_id'
    | 'timezone'
    | 'soat_due_at'
    | 'rtm_due_at'
    | 'operation_card_due_at'
    | 'soat_due_date'
    | 'rtm_due_date'
    | 'operation_card_due_date'
    | 'status'
> & {
    vehicle_type?: { id: number; code: string; name: string } | null;
    municipality?: {
        id: number;
        name: string;
        department_id: number;
        department?: { id: number; name: string };
    } | null;
    third_party?: {
        id: number;
        identification_number: string;
        first_name: string | null;
        first_lastname: string | null;
        company_name: string | null;
        is_natural_person: boolean;
    } | null;
};

const statusLabels: Record<string, string> = {
    active: 'Activo',
    maintenance: 'En Mantenimiento',
    retired: 'Retirado',
};

const dateFormatter = new Intl.DateTimeFormat('es-CO', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

function formatDate(date: string | null): string {
    if (!date) return '—';
    // Accept both 'Y-m-d' and full ISO datetime strings — Eloquent's
    // default `date` cast serializer returns the long form, while
    // helper methods like Carbon::toDateString() return the short one.
    const isoCandidate = /^\d{4}-\d{2}-\d{2}$/.test(date)
        ? `${date}T00:00:00`
        : date;
    const parsed = new Date(isoCandidate);
    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }
    return dateFormatter.format(parsed);
}

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'maintenance':
            return 'secondary';
        default:
            return 'outline';
    }
}

function ownerName(vehicle: ShowVehicle): string {
    const tp = vehicle.third_party;
    if (!tp) {
        return '—';
    }
    if (tp.is_natural_person) {
        return (
            `${tp.first_name ?? ''} ${tp.first_lastname ?? ''}`.trim() || '—'
        );
    }
    return tp.company_name ?? '—';
}

function municipalityDisplay(vehicle: ShowVehicle): string {
    const m = vehicle.municipality;
    if (!m) return '—';
    if (m.department?.name) {
        return `${m.name}, ${m.department.name}`;
    }
    return m.name;
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="font-medium">{children}</p>
        </div>
    );
}

interface RecentServiceRow {
    id: number;
    service_date: string;
    driver?: { first_name: string; first_lastname: string } | null;
    contract?: {
        third_party?: {
            company_name: string | null;
            first_name: string | null;
            first_lastname: string | null;
            is_natural_person: boolean;
        } | null;
    } | null;
    service_status: string;
}

function recentServiceClient(service: RecentServiceRow): string {
    const tp = service.contract?.third_party;
    if (!tp) return '—';
    if (tp.is_natural_person) {
        return (
            `${tp.first_name ?? ''} ${tp.first_lastname ?? ''}`.trim() || '—'
        );
    }
    return tp.company_name ?? '—';
}

function recentServiceDriverName(service: RecentServiceRow): string {
    const d = service.driver;
    if (!d) return '—';
    return `${d.first_name} ${d.first_lastname}`.trim();
}

const serviceStatusLabels: Record<string, string> = {
    open: 'Abierto',
    closed: 'Cerrado',
};

interface RecentLocationRow {
    id: number;
    vehicle_id: number;
    recorded_at: string | null;
    latitude: string;
    longitude: string;
    is_manual: boolean;
}

export default function VehiclesShow({
    vehicle,
    recentServices,
    recentLocations,
    municipalities,
    thirdParties,
    vehicleTypes,
}: {
    vehicle: ShowVehicle;
    recentServices: RecentServiceRow[];
    recentLocations: RecentLocationRow[];
    municipalities: MunicipalityOption[];
    thirdParties: ThirdPartyOption[];
    vehicleTypes: VehicleTypeOption[];
}) {
    const page = usePage<{
        auth?: { featureFlags?: { fuec?: boolean; gps?: boolean } };
    }>();
    const gpsEnabled = page.props.auth?.featureFlags?.gps === true;
    const [editOpen, setEditOpen] = useState(false);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Vehículos', href: vehicles.index().url },
        { title: vehicle.plate, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={vehicle.plate} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Header card */}
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <Truck className="size-8 text-muted-foreground" />
                                <div>
                                    <CardTitle className="font-mono text-2xl">
                                        {vehicle.plate}
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">
                                        {vehicle.internal_code}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant={statusVariant(vehicle.status)}>
                                    {statusLabels[vehicle.status] ??
                                        vehicle.status}
                                </Badge>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setEditOpen(true)}
                                >
                                    <Pencil className="mr-1 size-4" />
                                    Editar
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                </Card>

                <VehicleDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    mode="edit"
                    vehicle={vehicle}
                    municipalities={municipalities}
                    thirdParties={thirdParties}
                    vehicleTypes={vehicleTypes}
                />

                {/* Información General */}
                <Card>
                    <CardHeader>
                        <CardTitle>Información General</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <Field label="Marca">{vehicle.brand}</Field>
                            <Field label="Línea">{vehicle.line}</Field>
                            <Field label="Modelo">{vehicle.model_year}</Field>
                            <Field label="Tipo">
                                {vehicle.vehicle_type?.name ?? '—'}
                            </Field>
                            <Field label="Capacidad">
                                {vehicle.capacity} pasajeros
                            </Field>
                            <Field label="Número Móvil">
                                {vehicle.mobile_number ?? '—'}
                            </Field>
                            <Field label="Número de Motor">
                                {vehicle.engine_number ?? '—'}
                            </Field>
                            <Field label="Número de Chasis">
                                {vehicle.chassis_number ?? '—'}
                            </Field>
                            <Field label="Ciudad">
                                <span className="capitalize">
                                    {municipalityDisplay(vehicle)}
                                </span>
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                {/* Documentos */}
                <Card>
                    <CardHeader>
                        <CardTitle>Documentos</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-3">
                            <Field label="SOAT">
                                {formatDate(vehicle.soat_due_date)}
                            </Field>
                            <Field label="RTM">
                                {formatDate(vehicle.rtm_due_date)}
                            </Field>
                            <Field label="Tarjeta de Operación">
                                {formatDate(vehicle.operation_card_due_date)}
                            </Field>
                        </div>
                        <div>
                            <p className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                Estado
                            </p>
                            <VehicleDocumentPills vehicle={vehicle} />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Las alertas usan un margen de 30 días.
                        </p>
                    </CardContent>
                </Card>

                {/* Propietario */}
                <Card>
                    <CardHeader>
                        <CardTitle>Propietario</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {!vehicle.is_third_party && (
                            <div className="flex items-center gap-3 text-muted-foreground">
                                <Building2 className="size-5" />
                                <span>Empresa propia</span>
                            </div>
                        )}
                        {vehicle.is_third_party && vehicle.third_party && (
                            <div className="space-y-2">
                                <div className="flex items-center gap-2">
                                    <User className="size-5 text-muted-foreground" />
                                    <span className="font-medium">
                                        {ownerName(vehicle)}
                                    </span>
                                </div>
                                <Field label="Identificación">
                                    {vehicle.third_party.identification_number}
                                </Field>
                                <Link
                                    href={`/third-parties/${vehicle.third_party.id}`}
                                    className="text-sm text-primary hover:underline"
                                >
                                    Ver tercero
                                </Link>
                            </div>
                        )}
                        {vehicle.is_third_party && !vehicle.third_party && (
                            <p className="text-sm text-muted-foreground">
                                Tercero sin asignar.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Ubicaciones Recientes (GPS) — only when the module is enabled */}
                {gpsEnabled && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Ubicaciones Recientes (últimas 10)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentLocations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Sin registros de ubicación.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Fecha/Hora</TableHead>
                                            <TableHead>Coordenadas</TableHead>
                                            <TableHead>Origen</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentLocations.map((loc) => (
                                            <TableRow key={loc.id}>
                                                <TableCell className="font-mono text-xs whitespace-nowrap">
                                                    {loc.recorded_at
                                                        ? new Date(
                                                              loc.recorded_at,
                                                          ).toLocaleString(
                                                              'es-CO',
                                                          )
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {loc.latitude},{' '}
                                                    {loc.longitude}
                                                </TableCell>
                                                <TableCell>
                                                    {loc.is_manual ? (
                                                        <Badge variant="outline">
                                                            Manual
                                                        </Badge>
                                                    ) : (
                                                        <Badge>GPS</Badge>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Servicios Recientes */}
                <Card>
                    <CardHeader>
                        <CardTitle>Servicios Recientes (últimos 5)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentServices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sin servicios registrados.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Fecha</TableHead>
                                        <TableHead>Conductor</TableHead>
                                        <TableHead>Tercero</TableHead>
                                        <TableHead>Estado</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentServices.map((service) => (
                                        <TableRow key={service.id}>
                                            <TableCell>
                                                <Link
                                                    href={
                                                        services.show(
                                                            service.id,
                                                        ).url
                                                    }
                                                    className="text-primary hover:underline"
                                                >
                                                    {formatDate(
                                                        service.service_date,
                                                    )}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {recentServiceDriverName(
                                                    service,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {recentServiceClient(service)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        service.service_status ===
                                                        'closed'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {serviceStatusLabels[
                                                        service.service_status
                                                    ] ?? service.service_status}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

// Re-export Service for legacy callers; the page itself uses RecentServiceRow.
export type { Service };
