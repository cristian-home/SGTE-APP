import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import ContractDialog from '@/components/contracts/contract-dialog';
import FieldFooter from '@/components/field-footer';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import ServiceForm, {
    type ContractOption,
    type DriverOption,
    type VehicleOption,
} from '@/components/services/service-form';
import { type ThirdPartyOption } from '@/components/third-parties/third-party-combobox';
import { type DocumentTypeOption } from '@/components/third-parties/third-party-form';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { scrollToFirstError } from '@/lib/scroll-to-first-error';
import services from '@/routes/services';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Servicios', href: services.index().url },
    { title: 'Crear', href: services.create().url },
];

export default function ServicesCreate({
    vehicles,
    drivers,
    contracts,
    municipalities,
    prefill,
    reservedServiceNumber,
    executedDates = [],
    canBypassExecutedDay = false,
    thirdParties = [],
    documentTypes = [],
}: {
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    contracts: ContractOption[];
    municipalities: MunicipalityOption[];
    reservedServiceNumber: string;
    prefill?: {
        vehicle_id?: string;
        planned_start_time?: string;
        service_date?: string;
    } | null;
    executedDates?: string[];
    canBypassExecutedDay?: boolean;
    thirdParties?: ThirdPartyOption[];
    documentTypes?: DocumentTypeOption[];
}) {
    // The planner can prefill a clicked slot (date + time); combine them
    // into the single planned-start wall-clock the form now uses.
    const prefillStart = prefill?.service_date
        ? `${prefill.service_date} ${prefill.planned_start_time ?? '00:00'}`
        : '';

    const { data, setData, post, processing, errors } = useForm({
        service_number: reservedServiceNumber,
        contract_id: '',
        vehicle_id: prefill?.vehicle_id ?? '',
        driver_id: '',
        origin_municipality_id: '',
        origin_address: '',
        origin_coordinates: '',
        origin_coordinates_source: '',
        origin_coordinates_accuracy: '',
        origin_place_id: '',
        destination_municipality_id: '',
        destination_address: '',
        destination_coordinates: '',
        destination_coordinates_source: '',
        destination_coordinates_accuracy: '',
        destination_place_id: '',
        planned_start: prefillStart,
        planned_end: '',
        planned_duration: '',
        actual_start: '',
        actual_end: '',
        unit_value: '',
        quantity: '1',
        billing_groups: [] as string[],
        payment_method: 'credit',
        service_status: 'open',
        justification: '',
        manual_entry_justification: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        // The Save button stays enabled; on a validation error we surface the
        // inline messages and bring the first offending field into view.
        post(ServiceController.store().url, { onError: scrollToFirstError });
    }

    // BUG-10 — show warning + reveal justification when the picked service
    // date is on an EJECUTADO day. Backend (BUG-03 fix) requires Admin or
    // Super Admin role + 10–500-char justification to accept the create.
    const executedDateSet = useMemo(
        () => new Set(executedDates),
        [executedDates],
    );
    const serviceDate = data.planned_start.slice(0, 10);
    const isExecutedDay = serviceDate
        ? executedDateSet.has(serviceDate)
        : false;

    // Cascade: contract create dialog launched from the "+" button next to
    // the Contrato picker. On successful save, ContractController flashes
    // `created_contract_id`; we watch the flash and auto-select the new id.
    const canCascadeContract = thirdParties.length >= 0; // always true on this page; the page receives the props
    const [contractDialogOpen, setContractDialogOpen] = useState(false);
    const page = usePage();
    const flash = page.props.flash as
        | { created_contract_id?: number }
        | undefined;
    const consumedContractFlashRef = useRef<number | null>(null);
    useEffect(() => {
        const id = flash?.created_contract_id;
        if (!id || consumedContractFlashRef.current === id) return;
        consumedContractFlashRef.current = id;
        setData('contract_id', String(id));
    }, [flash?.created_contract_id, setData]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Crear Servicio" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Crear Servicio</h1>
                <form onSubmit={submit} className="space-y-6">
                    <ServiceForm
                        data={data}
                        setData={setData}
                        errors={errors}
                        vehicles={vehicles}
                        drivers={drivers}
                        contracts={contracts}
                        municipalities={municipalities}
                        mode="create"
                        serviceNumber={reservedServiceNumber}
                        onCreateContractClick={
                            canCascadeContract
                                ? () => setContractDialogOpen(true)
                                : undefined
                        }
                    />

                    {isExecutedDay && (
                        <Alert variant="destructive">
                            <AlertTitle>Día ejecutado</AlertTitle>
                            <AlertDescription>
                                {canBypassExecutedDay
                                    ? 'El día ya fue ejecutado. Agregar un servicio es una excepción y requiere justificación (10–500 caracteres). Quedará registrado en la auditoría.'
                                    : 'No se pueden agregar servicios a un día ejecutado. Esta acción está reservada al administrador.'}
                            </AlertDescription>
                        </Alert>
                    )}

                    {isExecutedDay && canBypassExecutedDay && (
                        <div className="grid gap-2">
                            <Label htmlFor="justification">
                                Justificación
                                <span className="text-destructive">{' *'}</span>
                            </Label>
                            <textarea
                                id="justification"
                                className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                value={data.justification}
                                onChange={(e) =>
                                    setData('justification', e.target.value)
                                }
                                maxLength={500}
                                minLength={10}
                                placeholder="Motivo del registro tardío en día ejecutado (mínimo 10 caracteres)."
                            />
                            <FieldFooter error={errors.justification} />
                        </div>
                    )}

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={
                                processing ||
                                (isExecutedDay && !canBypassExecutedDay)
                            }
                        >
                            Guardar
                        </Button>
                        <Link href={services.index().url}>
                            <Button type="button" variant="outline">
                                Cancelar
                            </Button>
                        </Link>
                    </div>
                </form>

                {/*
                    ContractDialog renders its own <form>. Keep it
                    OUTSIDE the service <form> above: React propagates
                    submit events through the component tree (not the
                    portaled DOM), so nesting it would also fire the
                    service form's onSubmit. See BUG-002.
                */}
                <ContractDialog
                    open={contractDialogOpen}
                    onOpenChange={setContractDialogOpen}
                    mode="create"
                    cascade
                    thirdParties={thirdParties}
                    documentTypes={documentTypes}
                    municipalities={municipalities}
                />
            </div>
        </AppLayout>
    );
}
