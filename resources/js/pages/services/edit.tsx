import { Head, Link, useForm } from '@inertiajs/react';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import ServiceForm, {
    type ContractOption,
    type DriverOption,
    type VehicleOption,
} from '@/components/services/service-form';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { scrollToFirstError } from '@/lib/scroll-to-first-error';
import services from '@/routes/services';
import { type BreadcrumbItem } from '@/types';
import type { DayStatus } from '@/types/models';

interface Service {
    id: number;
    service_number: string;
    contract_id: number;
    vehicle_id: number;
    driver_id: number | null;
    service_date: string;
    timezone: string;
    origin_municipality_id: number | null;
    origin_address: string | null;
    origin_coordinates: string | null;
    origin_coordinates_source: string | null;
    origin_coordinates_accuracy: string | null;
    origin_place_id: string | null;
    destination_municipality_id: number | null;
    destination_address: string | null;
    destination_coordinates: string | null;
    destination_coordinates_source: string | null;
    destination_coordinates_accuracy: string | null;
    destination_place_id: string | null;
    planned_start_local_datetime: string | null;
    planned_end_local_datetime: string | null;
    planned_duration: number;
    actual_start_local_datetime: string | null;
    actual_end_local_datetime: string | null;
    unit_value: string;
    quantity: number;
    billing_groups?: string[] | null;
    payment_method: string;
    service_status: string;
    service_incidents_count?: number;
}

export default function ServicesEdit({
    service,
    vehicles,
    drivers,
    contracts,
    municipalities,
    dayStatus,
    canEditExecuted,
    isAdmin,
}: {
    service: Service;
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    contracts: ContractOption[];
    municipalities: MunicipalityOption[];
    dayStatus?: DayStatus | null;
    canEditExecuted?: boolean;
    isAdmin?: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servicios', href: services.index().url },
        { title: 'Editar', href: ServiceController.edit(service.id).url },
    ];

    const isExecutedDay = dayStatus?.status === 'executed';
    const isFullyLocked = isExecutedDay && !canEditExecuted && !isAdmin;

    const { data, setData, put, processing, errors } = useForm({
        contract_id: String(service.contract_id),
        vehicle_id: String(service.vehicle_id),
        driver_id: service.driver_id ? String(service.driver_id) : '',
        origin_municipality_id: service.origin_municipality_id
            ? String(service.origin_municipality_id)
            : '',
        origin_address: service.origin_address ?? '',
        origin_coordinates: service.origin_coordinates ?? '',
        origin_coordinates_source: service.origin_coordinates_source ?? '',
        origin_coordinates_accuracy: service.origin_coordinates_accuracy ?? '',
        origin_place_id: service.origin_place_id ?? '',
        destination_municipality_id: service.destination_municipality_id
            ? String(service.destination_municipality_id)
            : '',
        destination_address: service.destination_address ?? '',
        destination_coordinates: service.destination_coordinates ?? '',
        destination_coordinates_source:
            service.destination_coordinates_source ?? '',
        destination_coordinates_accuracy:
            service.destination_coordinates_accuracy ?? '',
        destination_place_id: service.destination_place_id ?? '',
        planned_start: service.planned_start_local_datetime ?? '',
        planned_end: service.planned_end_local_datetime ?? '',
        planned_duration: String(service.planned_duration),
        actual_start: service.actual_start_local_datetime ?? '',
        actual_end: service.actual_end_local_datetime ?? '',
        timezone: service.timezone,
        unit_value: service.unit_value,
        quantity: String(service.quantity),
        billing_groups: service.billing_groups ?? [],
        payment_method: service.payment_method,
        service_status: service.service_status,
        justification: '',
        manual_entry_justification: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        // The Update button stays enabled; on a validation error we surface
        // the inline messages and bring the first offending field into view.
        put(ServiceController.update(service.id).url, {
            onError: scrollToFirstError,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Editar Servicio" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Editar Servicio</h1>
                <form onSubmit={submit} className="space-y-6">
                    <ServiceForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        vehicles={vehicles}
                        drivers={drivers}
                        contracts={contracts}
                        municipalities={municipalities}
                        incidentCount={service.service_incidents_count}
                        mode="edit"
                        serviceNumber={service.service_number}
                        dayStatus={dayStatus}
                        canEditExecuted={canEditExecuted}
                        isAdmin={isAdmin}
                    />

                    {!isFullyLocked && (
                        <div className="flex items-center gap-4">
                            <Button type="submit" disabled={processing}>
                                Actualizar
                            </Button>
                            <Link href={services.index().url}>
                                <Button type="button" variant="outline">
                                    Cancelar
                                </Button>
                            </Link>
                        </div>
                    )}

                    {isFullyLocked && (
                        <div className="flex items-center gap-4">
                            <Link href={services.index().url}>
                                <Button type="button" variant="outline">
                                    Volver
                                </Button>
                            </Link>
                        </div>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
