import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, FileText, Pencil } from 'lucide-react';
import { useState } from 'react';
import ContractDialog from '@/components/contracts/contract-dialog';
import { ContractPeriodPill } from '@/components/contracts/contract-period-pill';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import { type ThirdPartyOption } from '@/components/third-parties/third-party-combobox';
import { type DocumentTypeOption } from '@/components/third-parties/third-party-form';
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
import AppLayout from '@/layouts/app-layout';
import {
    contractDaysRemaining,
    contractPeriodStatus,
    contractStatusBadgeVariant,
    dateFormatter,
    parseDueDate,
} from '@/lib/document-status';
import contracts from '@/routes/contracts';
import services from '@/routes/services';
import thirdPartyRoutes from '@/routes/third-parties';

import type { BreadcrumbItem } from '@/types';
import type { Contract, DocumentType, ThirdParty } from '@/types/models';

// Local shape — does NOT extend Contract because the global Contract
// type uses `relation?: T` (undefined-only) while the show payload
// returns `relation: T | null`. `Pick + & relations` keeps both
// compatible (matches the vehicles/drivers/third-parties show pattern).
type ShowContract = Pick<
    Contract,
    | 'id'
    | 'contract_number'
    | 'third_party_id'
    | 'contract_object'
    | 'start_at'
    | 'end_at'
    | 'timezone'
    | 'start_date'
    | 'end_date'
    | 'route_description'
    | 'is_generic'
    | 'active'
    | 'billing_unit_type'
> & {
    third_party?:
        | (Pick<
              ThirdParty,
              | 'id'
              | 'identification_number'
              | 'is_natural_person'
              | 'first_name'
              | 'first_lastname'
              | 'company_name'
              | 'is_customer'
              | 'is_provider'
          > & {
              document_type?: Pick<DocumentType, 'id' | 'code' | 'name'> | null;
          })
        | null;
};

interface RecentServiceRow {
    id: number;
    service_date: string;
    service_status: string;
    vehicle?: { id: number; plate: string } | null;
    driver?: {
        id: number;
        first_name: string;
        first_lastname: string;
    } | null;
}

const CONTRACT_OBJECT_LABELS: Record<string, string> = {
    business: 'Empresarial',
    tourism: 'Turismo',
    health: 'Salud',
    occasional: 'Ocasional',
};

const SERVICE_STATUS_LABELS: Record<string, string> = {
    open: 'Abierto',
    closed: 'Cerrado',
};

function formatDate(date: string | null): string {
    const parsed = parseDueDate(date);
    if (parsed === null) {
        return '—';
    }
    return dateFormatter.format(parsed);
}

function customerName(tp: ShowContract['third_party']): string {
    if (!tp) return '—';
    if (tp.is_natural_person) {
        return (
            [tp.first_name, tp.first_lastname]
                .filter(Boolean)
                .join(' ')
                .trim() || '—'
        );
    }
    return tp.company_name ?? '—';
}

function driverFullName(driver: RecentServiceRow['driver']): string {
    if (!driver) return '—';
    return [driver.first_name, driver.first_lastname]
        .filter(Boolean)
        .join(' ')
        .trim();
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
            <div className="font-medium">{children}</div>
        </div>
    );
}

interface IncidentsBillingImpact {
    count: number;
    amount: number;
}

const currencyFormatter = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

export default function ContractsShow({
    contract,
    recentServices,
    incidentsBillingImpact,
    thirdParties,
    documentTypes,
    municipalities,
}: {
    contract: ShowContract;
    recentServices: RecentServiceRow[];
    incidentsBillingImpact?: IncidentsBillingImpact;
    thirdParties: ThirdPartyOption[];
    documentTypes: DocumentTypeOption[];
    municipalities: MunicipalityOption[];
}) {
    const [editOpen, setEditOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Contratos', href: contracts.index().url },
        { title: contract.contract_number, href: '#' },
    ];

    const tp = contract.third_party ?? null;
    const daysRemaining = contractDaysRemaining(contract.end_at);
    const status = contractPeriodStatus(contract);
    const daysLabel =
        daysRemaining === null
            ? '—'
            : daysRemaining < 0
              ? `${Math.abs(daysRemaining)} días vencido`
              : `${daysRemaining} días`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={contract.contract_number} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Header card */}
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <FileText className="size-8 text-muted-foreground" />
                                <div>
                                    <CardTitle className="font-mono text-2xl">
                                        {contract.contract_number}
                                    </CardTitle>
                                    <p className="text-sm text-muted-foreground">
                                        {customerName(tp)}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <ContractPeriodPill
                                    contract={contract}
                                    showDays
                                />
                                <Badge
                                    variant={
                                        contract.active ? 'default' : 'outline'
                                    }
                                >
                                    {contract.active ? 'Activo' : 'Inactivo'}
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

                <ContractDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    mode="edit"
                    contract={contract}
                    thirdParties={thirdParties}
                    documentTypes={documentTypes}
                    municipalities={municipalities}
                />

                {/* Datos del Contrato */}
                <Card>
                    <CardHeader>
                        <CardTitle>Datos del Contrato</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field label="Objeto">
                                {CONTRACT_OBJECT_LABELS[
                                    contract.contract_object
                                ] ?? contract.contract_object}
                            </Field>
                            <Field label="Contrato Genérico">
                                <Badge
                                    variant={
                                        contract.is_generic
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {contract.is_generic ? 'Sí' : 'No'}
                                </Badge>
                            </Field>
                        </div>
                        <Field label="Recorrido / Ruta">
                            <p className="text-sm whitespace-pre-wrap">
                                {contract.route_description ?? '—'}
                            </p>
                        </Field>
                    </CardContent>
                </Card>

                {/* Cliente */}
                <Card>
                    <CardHeader>
                        <CardTitle>Cliente</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {tp ? (
                            <div className="flex flex-wrap items-center justify-between gap-4">
                                <div>
                                    <p className="font-medium">
                                        {customerName(tp)}
                                    </p>
                                    <p className="font-mono text-sm text-muted-foreground">
                                        {(tp.document_type?.code ?? '?') +
                                            ' ' +
                                            tp.identification_number}
                                    </p>
                                </div>
                                <Button asChild size="sm" variant="outline">
                                    <Link
                                        href={thirdPartyRoutes.show(tp.id).url}
                                    >
                                        Ver tercero
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Sin cliente asociado.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Vigencia */}
                <Card>
                    <CardHeader>
                        <CardTitle>Vigencia</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-3">
                            <Field label="Fecha de Inicio">
                                {formatDate(contract.start_date)}
                            </Field>
                            <Field label="Fecha de Fin">
                                {formatDate(contract.end_date)}
                            </Field>
                            <Field label="Días restantes">
                                <Badge
                                    variant={contractStatusBadgeVariant(status)}
                                >
                                    {daysLabel}
                                </Badge>
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                {/* Novedades facturables del contrato (conditional) */}
                {incidentsBillingImpact && incidentsBillingImpact.count > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <AlertTriangle className="size-5 text-amber-600 dark:text-amber-400" />
                                Novedades que afectan facturación
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid items-center gap-6 md:grid-cols-2">
                                <div className="flex flex-col items-center justify-center gap-1 text-center">
                                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Total de novedades
                                    </p>
                                    <p className="text-3xl font-bold tabular-nums">
                                        {incidentsBillingImpact.count}
                                    </p>
                                </div>
                                <div className="flex flex-col items-center justify-center gap-1 text-center">
                                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Recargo acumulado
                                    </p>
                                    <p className="text-3xl font-bold text-amber-700 tabular-nums dark:text-amber-400">
                                        {currencyFormatter.format(
                                            incidentsBillingImpact.amount,
                                        )}
                                    </p>
                                </div>
                            </div>
                            <p className="mt-4 text-left text-xs text-muted-foreground">
                                Suma de las novedades marcadas como facturables
                                en todos los servicios del contrato. Se refleja
                                en el total de cada factura asociada.
                            </p>
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
                                        <TableHead>Vehículo</TableHead>
                                        <TableHead>Conductor</TableHead>
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
                                            <TableCell className="font-mono">
                                                {service.vehicle?.plate ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {driverFullName(service.driver)}
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
                                                    {SERVICE_STATUS_LABELS[
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
