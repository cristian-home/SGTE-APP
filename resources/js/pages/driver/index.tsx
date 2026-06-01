import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { index as driverDashboard } from '@/actions/App/Http/Controllers/DriverDashboardController';
import { DateNavigator } from '@/components/date-navigator';
import { DayTimeline } from '@/components/driver/day-timeline';
import { ServiceDetailDialog } from '@/components/driver/service-detail-dialog';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Service } from '@/types';

interface Driver {
    id: number;
    first_name: string;
    first_lastname: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Mis Servicios', href: '#' }];

export default function DriverDashboard({
    services,
    driver,
    selectedDate,
    isToday,
}: {
    services: Service[];
    driver?: Driver | null;
    selectedDate: string;
    isToday: boolean;
}) {
    const [selectedId, setSelectedId] = useState<number | null>(null);

    function confirmStart(serviceId: number) {
        router.post(`/driver/services/${serviceId}/confirm-start`);
    }

    function confirmEnd(serviceId: number) {
        router.post(`/driver/services/${serviceId}/confirm-end`);
    }

    function navigateToDate(newDate: string) {
        router.get(
            driverDashboard().url,
            { date: newDate },
            { preserveState: true, preserveScroll: true },
        );
    }

    const sharedConfig = usePage().props.config as
        | { operation_tz?: string; viewer_tz?: string }
        | undefined;
    const operationTz = sharedConfig?.operation_tz ?? 'America/Bogota';
    const viewerTz = sharedConfig?.viewer_tz ?? operationTz;
    // Header date is anchored on the selected day in operation TZ so the
    // service list (filtered server-side by the same Y-m-d) and the label
    // can never disagree, regardless of the viewer's browser TZ.
    const headerDate = new Intl.DateTimeFormat('es-CO', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: operationTz,
    }).format(new Date(selectedDate + 'T12:00:00'));
    const showViewerHint = viewerTz && viewerTz !== operationTz;

    const emptyStateMessage = isToday
        ? 'No tiene servicios asignados para hoy.'
        : 'No tiene servicios asignados para este día.';

    const selectedService = useMemo(
        () => services.find((s) => s.id === selectedId) ?? null,
        [services, selectedId],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Mis Servicios" />
            <div className="flex h-[calc(100svh-4rem)] flex-col gap-3 p-4 md:h-[calc(100svh-5rem)]">
                {/* Compact header: title + date on the left, date
                    navigator on the right. Wraps on narrow widths. */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-xl/tight font-bold tracking-tight sm:text-2xl">
                            Mis Servicios
                        </h1>
                        <p className="text-xs text-muted-foreground capitalize sm:text-sm">
                            {headerDate}
                            {!isToday && (
                                <span className="ml-2 text-muted-foreground/80 normal-case">
                                    · acciones limitadas
                                </span>
                            )}
                            {showViewerHint && (
                                <span className="ml-2 text-muted-foreground/80 normal-case">
                                    · {operationTz} (tu nav: {viewerTz})
                                </span>
                            )}
                        </p>
                    </div>
                    <DateNavigator
                        date={selectedDate}
                        operationTz={operationTz}
                        onDateChange={navigateToDate}
                        showFormattedLabel={false}
                    />
                </div>

                {!driver && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <p className="text-muted-foreground">
                                Su cuenta no está vinculada a un conductor.
                                Contacte al administrador.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {driver && services.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <p className="text-muted-foreground">
                                {emptyStateMessage}
                            </p>
                        </CardContent>
                    </Card>
                )}

                {driver && services.length > 0 && (
                    <DayTimeline
                        services={services}
                        isToday={isToday}
                        operationTz={operationTz}
                        onSelectService={setSelectedId}
                    />
                )}
            </div>

            <ServiceDetailDialog
                service={selectedService}
                isToday={isToday}
                open={selectedId !== null}
                onOpenChange={(o) => {
                    if (!o) {
                        setSelectedId(null);
                    }
                }}
                onConfirmStart={confirmStart}
                onConfirmEnd={confirmEnd}
            />
        </AppLayout>
    );
}
