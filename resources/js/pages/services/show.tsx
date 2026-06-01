import { Head, Link } from '@inertiajs/react';
import {
    Calendar,
    CircleDot,
    CreditCard,
    DollarSign,
    FileText,
    Hash,
    MapPin,
    Pencil,
    Plus,
    Trash2,
    Truck,
    User,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import ServiceIncidentController from '@/actions/App/Http/Controllers/ServiceIncidentController';
import {
    type BillingIncidentRow,
    IncidentsBillingBreakdown,
} from '@/components/billing/incidents-billing-breakdown';
import { Can } from '@/components/can';
import { DeleteConfirmationDialog } from '@/components/delete-confirmation-dialog';
import { IncidentSeverityPill } from '@/components/incidents/incident-severity-pill';
import RouteStaticMap from '@/components/services/route-static-map';
import { ServiceTimelineBar } from '@/components/services/service-timeline-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PaymentMethodLabel } from '@/enums/PaymentMethod';
import { Permission } from '@/enums/Permission';
import { ServiceStatusLabel } from '@/enums/ServiceStatus';
import AppLayout from '@/layouts/app-layout';
import { formatEventDate, formatEventTime } from '@/lib/datetime';
import services from '@/routes/services';
import { type BreadcrumbItem } from '@/types';
import type { Service } from '@/types/models';

interface DayStatusWithExecutor {
    id: number;
    date: string;
    status: string;
    executor_id: number | null;
    executed_at: string | null;
    executor?: {
        id: number;
        name: string;
    } | null;
}

function formatCurrency(value: string | number): string {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
    }).format(Number(value));
}

function formatDateTime(date: string): string {
    return new Intl.DateTimeFormat('es-CO', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(date));
}

function formatServiceTime(at: string | null, timezone: string): string {
    return formatEventTime(at, timezone) || '\u2014';
}

function municipalityDisplay(
    municipality?: { name: string; department?: { name: string } } | null,
    address?: string | null,
): string {
    if (!municipality) return address ?? '\u2014';
    const parts = [municipality.name];
    if (municipality.department)
        parts.push(`(${municipality.department.name})`);
    if (address) parts.push(`\u2014 ${address}`);
    return parts.join(' ');
}

function IconField({
    icon: Icon,
    label,
    children,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <Icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <div className="space-y-0.5">
                <dt className="text-xs font-medium text-muted-foreground">
                    {label}
                </dt>
                <dd className="text-sm">{children}</dd>
            </div>
        </div>
    );
}

function Field({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-0.5">
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

function formatIncidentTimestamp(reportedAt: string | null): string {
    if (!reportedAt) return '\u2014';
    // `reported_at` is now an ISO string (Phase 1: cast switched from
    // `timestamp` to `immutable_datetime`). The legacy Unix-int path
    // produced `NaN * 1000 = NaN` \u2192 Invalid Date \u2192 RangeError.
    const parsed = new Date(reportedAt);
    if (isNaN(parsed.getTime())) return '\u2014';
    return formatDateTime(parsed.toISOString());
}

interface RecentIncidentRow {
    id: number;
    service_id: number;
    incident_type_id: number;
    registrar_id: number | null;
    reported_at: string | null;
    is_driver_report: boolean;
    affects_billing: boolean;
    incident_type?: { id: number; name: string; severity: string } | null;
    registrar?: { id: number; name: string } | null;
}

export default function ServicesShow({
    service,
    dayStatus,
    recentIncidents,
    billingIncidents,
}: {
    service: Service;
    dayStatus?: DayStatusWithExecutor | null;
    recentIncidents?: RecentIncidentRow[];
    billingIncidents?: BillingIncidentRow[];
}) {
    const clientName = service.contract?.third_party
        ? service.contract.third_party.company_name ||
          `${service.contract.third_party.first_name} ${service.contract.third_party.first_lastname}`
        : '';

    const pageTitle = `Detalle de Servicio ${service.contract?.contract_number ?? ''}${clientName ? ` - ${clientName}` : ''}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servicios', href: services.index().url },
        {
            title: service.contract?.contract_number ?? 'Ver',
            href: '#',
        },
    ];

    const driverName = service.driver
        ? `${service.driver.first_name} ${service.driver.first_lastname}`
        : '\u2014';

    const actualDuration =
        service.actual_start_at && service.actual_end_at
            ? (() => {
                  const start = new Date(service.actual_start_at).getTime();
                  const end = new Date(service.actual_end_at).getTime();
                  const diff = Math.round((end - start) / 60000);
                  return diff > 0 ? `${diff} min` : '\u2014';
              })()
            : '\u2014';

    // Prefer the dedicated recentIncidents payload (last 5 by
    // reported_at DESC, server-pinned) and fall back to the full
    // relation for backward compatibility.
    const incidents =
        recentIncidents ??
        (service.service_incidents as unknown as RecentIncidentRow[]) ??
        [];
    const [deleteUrl, setDeleteUrl] = useState<string | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={pageTitle} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Heading row */}
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <span className="rounded-md bg-muted px-2 py-1 font-mono text-base font-semibold tracking-wide tabular-nums">
                            {service.service_number}
                        </span>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {pageTitle}
                        </h1>
                        {(service.service_incidents_count ?? 0) > 0 && (
                            <Badge variant="destructive">
                                {service.service_incidents_count} novedad
                                {(service.service_incidents_count ?? 0) > 1
                                    ? 'es'
                                    : ''}
                            </Badge>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        <Can permission={Permission.UPDATE_PROJECTED_SERVICES}>
                            <Link href={services.edit(service.id).url}>
                                <Button>Editar</Button>
                            </Link>
                        </Can>
                        <Link href={services.index().url}>
                            <Button variant="outline">Volver</Button>
                        </Link>
                    </div>
                </div>

                {/* Day-executed alert */}
                {dayStatus?.status === 'executed' && (
                    <Alert>
                        <AlertTitle>
                            <Badge variant="secondary">Día Ejecutado</Badge>
                        </AlertTitle>
                        <AlertDescription>
                            Ejecutado por{' '}
                            {dayStatus.executor?.name ?? 'Usuario'} el{' '}
                            {dayStatus.executed_at
                                ? formatDateTime(dayStatus.executed_at)
                                : '\u2014'}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Row 1: Datos Generales + Detalle de la Ruta */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Datos Generales del Servicio */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Datos Generales del Servicio</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <IconField icon={Calendar} label="Fecha">
                                    {formatEventDate(
                                        service.planned_start_at,
                                        service.timezone,
                                        { dateStyle: 'long' },
                                    )}
                                </IconField>
                                <IconField icon={FileText} label="Contrato">
                                    {service.contract?.contract_number ??
                                        '\u2014'}
                                    {service.contract?.third_party && (
                                        <span className="ml-1 text-muted-foreground">
                                            -{' '}
                                            {service.contract.third_party
                                                .company_name ||
                                                `${service.contract.third_party.first_name} ${service.contract.third_party.first_lastname}`}
                                        </span>
                                    )}
                                </IconField>
                                <IconField icon={Truck} label="Vehículo">
                                    {service.vehicle?.plate ?? '\u2014'}
                                    {service.vehicle?.is_third_party &&
                                        service.vehicle?.third_party && (
                                            <span className="ml-1 text-muted-foreground">
                                                (Tercero:{' '}
                                                {service.vehicle.third_party
                                                    .company_name ||
                                                    `${service.vehicle.third_party.first_name} ${service.vehicle.third_party.first_lastname}`}
                                                )
                                            </span>
                                        )}
                                </IconField>
                                <IconField icon={User} label="Conductor">
                                    {driverName}
                                </IconField>
                                <IconField icon={MapPin} label="Origen">
                                    <span className="capitalize">
                                        {municipalityDisplay(
                                            service.origin_municipality,
                                        )}
                                    </span>
                                </IconField>
                                <IconField icon={CircleDot} label="Estado">
                                    <Badge
                                        variant={
                                            service.service_status === 'open'
                                                ? 'secondary'
                                                : 'default'
                                        }
                                    >
                                        {ServiceStatusLabel[
                                            service.service_status as keyof typeof ServiceStatusLabel
                                        ] ?? service.service_status}
                                    </Badge>
                                </IconField>
                            </dl>
                        </CardContent>
                    </Card>

                    {/* Detalle de la Ruta */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Detalle de la Ruta</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <RouteStaticMap
                                origin={service.origin_coordinates ?? null}
                                destination={
                                    service.destination_coordinates ?? null
                                }
                                geometry={service.route_geometry ?? null}
                            />
                        </CardContent>
                    </Card>
                </div>

                {/* Row 2: Cronograma y Tiempos + Resumen de Facturación */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Cronograma y Tiempos */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Cronograma y Tiempos</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <ServiceTimelineBar
                                plannedStartAt={service.planned_start_at}
                                plannedDuration={service.planned_duration}
                                actualStartAt={service.actual_start_at}
                                actualEndAt={service.actual_end_at}
                                timezone={service.timezone}
                            />
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Field label="Hora Inicio Planificada">
                                    {formatServiceTime(
                                        service.planned_start_at,
                                        service.timezone,
                                    )}
                                </Field>
                                <Field label="Duración Planificada">
                                    {service.planned_duration} min
                                </Field>
                                <Field label="Hora Inicio Real">
                                    {formatServiceTime(
                                        service.actual_start_at,
                                        service.timezone,
                                    )}
                                </Field>
                                <Field label="Hora Fin Real">
                                    {formatServiceTime(
                                        service.actual_end_at,
                                        service.timezone,
                                    )}
                                </Field>
                                <Field label="Duración Real">
                                    {actualDuration}
                                </Field>
                            </dl>
                        </CardContent>
                    </Card>

                    {/* Resumen de Facturación */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Resumen de Facturación</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {/* Decorative icons row */}
                            <div className="flex justify-around">
                                <Users className="size-6 text-muted-foreground/50" />
                                <DollarSign className="size-6 text-muted-foreground/50" />
                                <Hash className="size-6 text-muted-foreground/50" />
                                <CreditCard className="size-6 text-muted-foreground/50" />
                            </div>
                            {/* Billing fields */}
                            <dl className="grid grid-cols-4 gap-3 text-center">
                                <Field label="Grupos de Facturación">
                                    {service.billing_groups &&
                                    service.billing_groups.length > 0 ? (
                                        <div className="flex flex-wrap justify-center gap-1">
                                            {service.billing_groups.map(
                                                (tag) => (
                                                    <Badge
                                                        key={tag}
                                                        variant="secondary"
                                                    >
                                                        {tag}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        '\u2014'
                                    )}
                                </Field>
                                <Field label="Valor Unitario">
                                    {formatCurrency(service.unit_value)}
                                </Field>
                                <Field label="Cantidad">
                                    {service.quantity}
                                </Field>
                                <Field label="Método de Pago">
                                    <Badge variant="outline">
                                        {PaymentMethodLabel[
                                            service.payment_method as keyof typeof PaymentMethodLabel
                                        ] ?? service.payment_method}
                                    </Badge>
                                </Field>
                            </dl>
                        </CardContent>
                    </Card>
                </div>

                {/* Row 3 (conditional): Impacto de novedades en facturación */}
                {billingIncidents && billingIncidents.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Impacto de novedades en facturación
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <IncidentsBillingBreakdown
                                unitValue={service.unit_value}
                                quantity={service.quantity}
                                incidents={billingIncidents}
                            />
                        </CardContent>
                    </Card>
                )}

                {/* Row 4: Novedades */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <CardTitle>Novedades</CardTitle>
                                {incidents.length > 0 && (
                                    <Badge variant="secondary">
                                        {incidents.length}
                                    </Badge>
                                )}
                            </div>
                            <Can permission={Permission.CREATE_INCIDENTS}>
                                <Link
                                    href={`${ServiceIncidentController.create.url()}?service_id=${service.id}`}
                                >
                                    <Button size="sm">
                                        <Plus className="mr-1 size-4" />
                                        Registrar Novedad
                                    </Button>
                                </Link>
                            </Can>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {incidents.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                Sin novedades registradas.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-xs text-muted-foreground">
                                            <th className="pb-2 font-medium">
                                                Tipo
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Severidad
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Fecha Reporte
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Registrador
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Facturación
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Acciones
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {incidents.map(
                                            (incident: RecentIncidentRow) => (
                                                <tr key={incident.id}>
                                                    <td className="py-2 pr-3">
                                                        <Link
                                                            href={ServiceIncidentController.show.url(
                                                                incident.id,
                                                            )}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {incident
                                                                .incident_type
                                                                ?.name ??
                                                                '\u2014'}
                                                        </Link>
                                                    </td>
                                                    <td className="py-2 pr-3">
                                                        <IncidentSeverityPill
                                                            severity={
                                                                incident
                                                                    .incident_type
                                                                    ?.severity ??
                                                                null
                                                            }
                                                        />
                                                    </td>
                                                    <td className="py-2 pr-3 whitespace-nowrap">
                                                        {formatIncidentTimestamp(
                                                            incident.reported_at,
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3">
                                                        {incident.registrar
                                                            ?.name ?? '\u2014'}
                                                    </td>
                                                    <td className="py-2 pr-3">
                                                        {incident.affects_billing && (
                                                            <Badge variant="destructive">
                                                                Afecta
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="py-2">
                                                        <div className="flex items-center gap-1">
                                                            <Can
                                                                permission={
                                                                    Permission.UPDATE_INCIDENTS
                                                                }
                                                            >
                                                                <Link
                                                                    href={ServiceIncidentController.edit.url(
                                                                        incident.id,
                                                                    )}
                                                                >
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="size-7"
                                                                    >
                                                                        <Pencil className="size-3.5" />
                                                                    </Button>
                                                                </Link>
                                                            </Can>
                                                            <Can
                                                                permission={
                                                                    Permission.DELETE_INCIDENTS
                                                                }
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-7 text-destructive hover:text-destructive"
                                                                    onClick={() =>
                                                                        setDeleteUrl(
                                                                            ServiceIncidentController.destroy.url(
                                                                                incident.id,
                                                                            ),
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-3.5" />
                                                                </Button>
                                                            </Can>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
            <DeleteConfirmationDialog
                open={deleteUrl !== null}
                onOpenChange={(open) => !open && setDeleteUrl(null)}
                deleteUrl={deleteUrl ?? ''}
            />
        </AppLayout>
    );
}
