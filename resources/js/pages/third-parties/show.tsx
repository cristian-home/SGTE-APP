import { Head, Link } from '@inertiajs/react';
import { Building2, Pencil, UserCircle2 } from 'lucide-react';
import { useState } from 'react';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import ThirdPartyDialog from '@/components/third-parties/third-party-dialog';
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
import { type VehicleType, VehicleTypeLabel } from '@/enums/VehicleType';
import AppLayout from '@/layouts/app-layout';
import contracts from '@/routes/contracts';
import thirdParties from '@/routes/third-parties';
import vehicles from '@/routes/vehicles';

import type { BreadcrumbItem } from '@/types';
import type { ThirdParty } from '@/types/models';

// Local shape — does NOT extend ThirdParty because the global type
// uses `relation?: T` (undefined-only) while the show payload sends
// `relation: T | null`. Picking + & relations keeps both compatible
// (matches the vehicles/show.tsx + drivers/show.tsx pattern).
type ShowThirdParty = Pick<
    ThirdParty,
    | 'id'
    | 'document_type_id'
    | 'identification_number'
    | 'is_natural_person'
    | 'first_name'
    | 'second_name'
    | 'first_lastname'
    | 'second_lastname'
    | 'company_name'
    | 'trade_name'
    | 'municipality_id'
    | 'address'
    | 'phone'
    | 'email'
    | 'is_customer'
    | 'is_provider'
    | 'active'
> & {
    document_type?: { id: number; code: string; name: string } | null;
    municipality?: {
        id: number;
        name: string;
        department_id: number;
        department?: { id: number; name: string };
    } | null;
};

interface RecentVehicleRow {
    id: number;
    plate: string;
    internal_code: string | null;
    type: string;
    status: string;
}

interface RecentContractRow {
    id: number;
    contract_number: string;
    contract_object: string;
    start_date: string;
    end_date: string;
    active: boolean;
}

const dateFormatter = new Intl.DateTimeFormat('es-CO', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

function formatDate(date: string | null): string {
    if (!date) return '—';
    const isoCandidate = /^\d{4}-\d{2}-\d{2}$/.test(date)
        ? `${date}T00:00:00`
        : date;
    const parsed = new Date(isoCandidate);
    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }
    return dateFormatter.format(parsed);
}

const vehicleStatusLabels: Record<string, string> = {
    active: 'Activo',
    maintenance: 'En Mantenimiento',
    retired: 'Retirado',
};

function fullName(tp: ShowThirdParty): string {
    if (tp.is_natural_person) {
        return [
            tp.first_name,
            tp.second_name,
            tp.first_lastname,
            tp.second_lastname,
        ]
            .filter(Boolean)
            .join(' ')
            .trim();
    }
    return tp.company_name ?? '—';
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

export default function ThirdPartiesShow({
    thirdParty,
    recentVehicles,
    recentContracts,
    documentTypes,
    municipalities,
}: {
    thirdParty: ShowThirdParty;
    recentVehicles: RecentVehicleRow[];
    recentContracts: RecentContractRow[];
    documentTypes: DocumentTypeOption[];
    municipalities: MunicipalityOption[];
}) {
    const [editOpen, setEditOpen] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Terceros', href: thirdParties.index().url },
        { title: fullName(thirdParty) || '#', href: '#' },
    ];

    const docLabel = thirdParty.document_type
        ? `${thirdParty.document_type.code} ${thirdParty.identification_number}`
        : thirdParty.identification_number;

    const Icon = thirdParty.is_natural_person ? UserCircle2 : Building2;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fullName(thirdParty) || 'Tercero'} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Header card (unconditional) */}
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <Icon className="size-8 text-muted-foreground" />
                                <div>
                                    <CardTitle className="text-2xl">
                                        {fullName(thirdParty)}
                                    </CardTitle>
                                    {!thirdParty.is_natural_person &&
                                        thirdParty.trade_name && (
                                            <p className="text-sm text-muted-foreground">
                                                {thirdParty.trade_name}
                                            </p>
                                        )}
                                    <p className="font-mono text-sm text-muted-foreground">
                                        {docLabel}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge
                                    variant={
                                        thirdParty.active
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {thirdParty.active ? 'Activo' : 'Inactivo'}
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

                <ThirdPartyDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    mode="edit"
                    thirdParty={thirdParty}
                    documentTypes={documentTypes}
                    municipalities={municipalities}
                />

                {/* Información General (unconditional) */}
                <Card>
                    <CardHeader>
                        <CardTitle>Información General</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            <Field label="Tipo de Documento">
                                {thirdParty.document_type
                                    ? `${thirdParty.document_type.code} — ${thirdParty.document_type.name}`
                                    : '—'}
                            </Field>
                            <Field label="Identificación">
                                {thirdParty.identification_number}
                            </Field>
                            <Field label="Tipo Persona">
                                {thirdParty.is_natural_person
                                    ? 'Natural'
                                    : 'Jurídica'}
                            </Field>
                            {!thirdParty.is_natural_person &&
                                thirdParty.trade_name && (
                                    <Field label="Nombre Comercial">
                                        {thirdParty.trade_name}
                                    </Field>
                                )}
                        </div>
                        <div>
                            <p className="mb-2 text-xs tracking-wide text-muted-foreground uppercase">
                                Roles
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant={
                                        thirdParty.is_customer
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {thirdParty.is_customer
                                        ? 'Cliente'
                                        : 'No es cliente'}
                                </Badge>
                                <Badge
                                    variant={
                                        thirdParty.is_provider
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {thirdParty.is_provider
                                        ? 'Proveedor'
                                        : 'No es proveedor'}
                                </Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Datos de Contacto (unconditional) */}
                <Card>
                    <CardHeader>
                        <CardTitle>Datos de Contacto</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field label="Ciudad">
                                <span className="capitalize">
                                    {thirdParty.municipality
                                        ? `${thirdParty.municipality.name}${
                                              thirdParty.municipality.department
                                                  ? ', ' +
                                                    thirdParty.municipality
                                                        .department.name
                                                  : ''
                                          }`
                                        : '—'}
                                </span>
                            </Field>
                            <Field label="Dirección">
                                {thirdParty.address ?? '—'}
                            </Field>
                            <Field label="Teléfono">
                                {thirdParty.phone ?? '—'}
                            </Field>
                            <Field label="Correo">
                                {thirdParty.email ?? '—'}
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                {/* Vehículos del Tercero (conditional — only when is_provider) */}
                {thirdParty.is_provider && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Vehículos del Tercero (últimos 5)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentVehicles.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Sin vehículos asociados.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Placa</TableHead>
                                            <TableHead>Cód. Interno</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Estado</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentVehicles.map((v) => (
                                            <TableRow key={v.id}>
                                                <TableCell className="font-mono">
                                                    <Link
                                                        href={
                                                            vehicles.show(v.id)
                                                                .url
                                                        }
                                                        className="font-mono text-primary hover:underline"
                                                    >
                                                        {v.plate}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {v.internal_code ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {VehicleTypeLabel[
                                                        v.type as VehicleType
                                                    ] ?? v.type}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            v.status ===
                                                            'active'
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {vehicleStatusLabels[
                                                            v.status
                                                        ] ?? v.status}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Contratos (conditional — only when is_customer) */}
                {thirdParty.is_customer && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Contratos (últimos 5)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentContracts.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Sin contratos registrados.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Número</TableHead>
                                            <TableHead>Objeto</TableHead>
                                            <TableHead>Vigencia</TableHead>
                                            <TableHead>Estado</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recentContracts.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell className="font-mono">
                                                    <Link
                                                        href={
                                                            contracts.show(c.id)
                                                                .url
                                                        }
                                                        className="text-primary hover:underline"
                                                    >
                                                        {c.contract_number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {c.contract_object}
                                                </TableCell>
                                                <TableCell className="text-sm whitespace-nowrap">
                                                    {formatDate(c.start_date)} →{' '}
                                                    {formatDate(c.end_date)}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            c.active
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {c.active
                                                            ? 'Activo'
                                                            : 'Inactivo'}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
