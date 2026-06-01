import { Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Ban,
    CheckCircle2,
    Clock,
    Flag,
    MapPin,
    Play,
    Truck,
} from 'lucide-react';
import { useState } from 'react';
import OpenInMapsButton from '@/components/services/open-in-maps-button';
import RouteStaticMap from '@/components/services/route-static-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { ServiceStatusLabel } from '@/enums/ServiceStatus';
import { formatEventTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { Service } from '@/types';

function formatServiceTime(at: string | null, timezone: string): string {
    return formatEventTime(at, timezone) || '—';
}

function municipalityName(municipality?: { name: string } | null): string {
    return municipality?.name ?? '—';
}

function clientName(service: Service): string {
    const tp = service.contract?.third_party;
    if (!tp) {
        return 'Sin contrato';
    }
    return (
        tp.company_name ||
        `${tp.first_name ?? ''} ${tp.first_lastname ?? ''}`.trim() ||
        'Sin contrato'
    );
}

interface ServiceDetailDialogProps {
    service: Service | null;
    isToday: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirmStart: (serviceId: number) => void;
    onConfirmEnd: (serviceId: number) => void;
}

export function ServiceDetailDialog({
    service,
    isToday,
    open,
    onOpenChange,
    onConfirmStart,
    onConfirmEnd,
}: ServiceDetailDialogProps) {
    const [declineOpen, setDeclineOpen] = useState(false);
    const declineForm = useForm({ reason_text: '' });

    if (!service) {
        return null;
    }

    const hasStarted = !!service.actual_start_at;
    const hasEnded = !!service.actual_end_at;
    const isDeclined = !!service.driver_declined_at;
    const isClosed = service.service_status === 'closed';
    const incidentCount = service.service_incidents_count ?? 0;

    function submitDecline(e: React.FormEvent) {
        e.preventDefault();
        if (!service) {
            return;
        }
        declineForm.post(`/driver/services/${service.id}/decline`, {
            onSuccess: () => {
                setDeclineOpen(false);
                declineForm.reset();
                onOpenChange(false);
            },
        });
    }

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent className="flex max-h-[90svh] flex-col gap-0 p-0 sm:max-w-2xl">
                    {/* Sticky header — vehicle identity + status + the
                        origin→destination ruler. Anchored above the
                        scroll area so the driver never loses track of
                        which service they're looking at. */}
                    <div className="shrink-0 space-y-3 border-b px-4 pt-4 pb-3 sm:space-y-4 sm:px-6 sm:pt-6 sm:pb-4">
                        <DialogHeader>
                            <div className="flex items-start justify-between gap-2 sm:gap-3">
                                <div className="flex items-start gap-2 sm:gap-3">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/40 sm:size-12">
                                        <Truck className="size-5 text-muted-foreground sm:size-6" />
                                    </div>
                                    <div className="min-w-0">
                                        <DialogTitle className="font-mono text-lg/tight sm:text-xl">
                                            {service.vehicle?.plate ?? '—'}
                                            <span className="ml-2 align-middle text-sm font-normal text-muted-foreground">
                                                {service.service_number}
                                            </span>
                                        </DialogTitle>
                                        <DialogDescription className="mt-0.5 line-clamp-1">
                                            {clientName(service)}
                                        </DialogDescription>
                                    </div>
                                </div>
                                <div className="flex shrink-0 items-center gap-2 pr-6">
                                    {incidentCount > 0 && (
                                        <Badge variant="destructive">
                                            {incidentCount} novedad
                                            {incidentCount > 1 ? 'es' : ''}
                                        </Badge>
                                    )}
                                    <Badge
                                        variant={
                                            isClosed ? 'default' : 'secondary'
                                        }
                                        className="gap-1.5"
                                    >
                                        <span
                                            className={
                                                isClosed
                                                    ? 'size-1.5 rounded-full bg-current'
                                                    : 'size-1.5 rounded-full bg-foreground'
                                            }
                                            aria-hidden="true"
                                        />
                                        {ServiceStatusLabel[
                                            service.service_status as keyof typeof ServiceStatusLabel
                                        ] ?? service.service_status}
                                    </Badge>
                                </div>
                            </div>
                        </DialogHeader>

                        {/* Route row — A pin · origin ─────► B pin · destination */}
                        <div className="flex items-center gap-3 text-sm">
                            <div className="flex min-w-0 shrink-0 items-center gap-2">
                                <MapPin className="size-4 text-muted-foreground" />
                                <span className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    A
                                </span>
                                <span className="truncate text-sm font-medium capitalize">
                                    {municipalityName(
                                        service.origin_municipality,
                                    )}
                                </span>
                            </div>
                            <div className="relative flex-1">
                                <div className="h-px w-full bg-border" />
                                <div className="absolute top-1/2 right-0 size-0 -translate-y-1/2 border-y-4 border-l-[6px] border-y-transparent border-l-border" />
                            </div>
                            <div className="flex min-w-0 shrink-0 items-center gap-2">
                                <MapPin className="size-4 text-muted-foreground" />
                                <span className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                    B
                                </span>
                                <span className="truncate text-sm font-medium capitalize">
                                    {municipalityName(
                                        service.destination_municipality,
                                    )}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Scrollable body — only this part scrolls when the
                        content exceeds the dialog's max height. */}
                    <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3 sm:space-y-4 sm:px-6 sm:py-4">
                        <RouteStaticMap
                            origin={service.origin_coordinates ?? null}
                            destination={
                                service.destination_coordinates ?? null
                            }
                            geometry={service.route_geometry ?? null}
                            width={560}
                            height={260}
                        />

                        {/* Métricas de Tiempo — bordered card with the planned
                            and actual horizons side by side. */}
                        <div className="rounded-lg border bg-muted/20 p-4">
                            <p className="mb-3 text-sm font-medium">
                                M&eacute;tricas de Tiempo
                            </p>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="flex items-start gap-2.5">
                                    <Clock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <div>
                                        <p className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                            Horario planificado
                                        </p>
                                        <p className="text-sm">
                                            {formatServiceTime(
                                                service.planned_start_at,
                                                service.timezone,
                                            )}{' '}
                                            ({service.planned_duration} min)
                                        </p>
                                    </div>
                                </div>
                                {hasStarted && (
                                    <div className="flex items-start gap-2.5">
                                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                        <div>
                                            <p className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                                {hasEnded
                                                    ? 'Horario real'
                                                    : 'Horario de inicio real'}
                                            </p>
                                            <p className="text-sm">
                                                {formatServiceTime(
                                                    service.actual_start_at,
                                                    service.timezone,
                                                )}
                                                {hasEnded && (
                                                    <>
                                                        {' '}
                                                        &mdash;{' '}
                                                        {formatServiceTime(
                                                            service.actual_end_at,
                                                            service.timezone,
                                                        )}
                                                    </>
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Sticky footer — declined banner (when applicable),
                        secondary action grid, and the full-width primary
                        CTA. Always visible regardless of how long the
                        scrollable body grows. */}
                    <DialogFooter className="shrink-0 flex-col! gap-2 border-t px-4 pt-3 pb-4 sm:px-6 sm:pt-4 sm:pb-6">
                        {isDeclined && (
                            <div className="w-full rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                <p className="font-medium">
                                    Servicio declinado &mdash; pendiente de
                                    reasignaci&oacute;n
                                </p>
                                {service.driver_decline_reason && (
                                    <p className="mt-1 text-xs opacity-80">
                                        {service.driver_decline_reason}
                                    </p>
                                )}
                            </div>
                        )}
                        {/* Row 1 — secondary actions. Mobile always
                            stacks them in a single column. Desktop uses
                            3 columns when the Declinar button is in play
                            and collapses to 2 once the service is
                            declined, since keeping a permanently-disabled
                            Declinar button just to fill the grid is
                            redundant. */}
                        <div
                            className={cn(
                                'grid w-full grid-cols-1 gap-2',
                                isDeclined
                                    ? 'sm:grid-cols-2'
                                    : 'sm:grid-cols-3',
                            )}
                        >
                            <OpenInMapsButton
                                variant="outline"
                                origin={service.origin_coordinates ?? null}
                                destination={
                                    service.destination_coordinates ?? null
                                }
                            />

                            {isClosed ? (
                                <Button variant="outline" disabled>
                                    <AlertCircle className="mr-1 size-4" />
                                    Registrar Novedad
                                </Button>
                            ) : (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/service-incidents/create?service_id=${service.id}`}
                                    >
                                        <AlertCircle className="mr-1 size-4" />
                                        Registrar Novedad
                                    </Link>
                                </Button>
                            )}

                            {!isDeclined && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDeclineOpen(true)}
                                    disabled={
                                        !isToday || hasStarted || isClosed
                                    }
                                    className="text-destructive hover:text-destructive"
                                >
                                    <Ban className="mr-1 size-4" />
                                    Declinar servicio
                                </Button>
                            )}
                        </div>

                        {/* Row 2 — primary action, full width. Its label
                            tracks the service's current lifecycle stage so
                            the driver always sees what happens next (or
                            why nothing can happen). */}
                        {(() => {
                            if (!isToday) {
                                return (
                                    <Button
                                        className="w-full"
                                        disabled
                                        variant="secondary"
                                    >
                                        Disponible solo el d&iacute;a del
                                        servicio
                                    </Button>
                                );
                            }
                            if (isDeclined) {
                                return (
                                    <Button
                                        className="w-full"
                                        disabled
                                        variant="secondary"
                                    >
                                        <Ban className="mr-1 size-4" />
                                        Servicio declinado
                                    </Button>
                                );
                            }
                            if (isClosed || (hasStarted && hasEnded)) {
                                return (
                                    <Button
                                        className="w-full"
                                        disabled
                                        variant="secondary"
                                    >
                                        <CheckCircle2 className="mr-1 size-4" />
                                        Servicio completado
                                    </Button>
                                );
                            }
                            if (hasStarted) {
                                return (
                                    <Button
                                        className="w-full"
                                        onClick={() => onConfirmEnd(service.id)}
                                    >
                                        <Flag className="mr-1 size-4" />
                                        Confirmar Fin
                                    </Button>
                                );
                            }
                            return (
                                <Button
                                    className="w-full"
                                    onClick={() => onConfirmStart(service.id)}
                                >
                                    <Play className="mr-1 size-4" />
                                    Confirmar Inicio
                                </Button>
                            );
                        })()}
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={declineOpen}
                onOpenChange={(o) => {
                    setDeclineOpen(o);
                    if (!o) {
                        declineForm.reset();
                        declineForm.clearErrors();
                    }
                }}
            >
                <DialogContent>
                    <form onSubmit={submitDecline} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Declinar servicio</DialogTitle>
                            <DialogDescription>
                                Explique por qu&eacute; no puede ejecutar el
                                servicio. Esta acci&oacute;n notifica a
                                operaciones y marca el servicio como pendiente
                                de reasignaci&oacute;n.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2">
                            <Label htmlFor="reason_text">
                                Motivo del rechazo
                                <span className="text-destructive"> *</span>
                            </Label>
                            <textarea
                                id="reason_text"
                                className="flex min-h-25 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                value={declineForm.data.reason_text}
                                placeholder="Ej: Incapacidad médica, vehículo con falla mecánica, etc."
                                minLength={10}
                                maxLength={1000}
                                onChange={(e) =>
                                    declineForm.setData(
                                        'reason_text',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            {declineForm.errors.reason_text && (
                                <p className="text-sm text-destructive">
                                    {declineForm.errors.reason_text}
                                </p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setDeclineOpen(false)}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={declineForm.processing}
                            >
                                Confirmar rechazo
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
