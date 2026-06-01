import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo } from 'react';
import { Can } from '@/components/can';
import { DataTable } from '@/components/data-table';
import { DataTableDateRangeFilter } from '@/components/data-table/data-table-date-range-filter';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import { Button } from '@/components/ui/button';
import { type VehicleOption } from '@/components/vehicles/vehicle-combobox';
import { Permission } from '@/enums/Permission';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';
import { viewerToday } from '@/lib/datetime';
import services from '@/routes/services';
import { columns } from './columns';

import type {
    BreadcrumbItem,
    FilterDefinition,
    PaginatedData,
    Service,
} from '@/types';

export interface ContractFilterOption {
    id: number;
    contract_number: string;
    third_party_id: number;
    third_party?: {
        id: number;
        company_name: string | null;
        first_name: string | null;
        first_lastname: string | null;
        is_natural_person: boolean;
    } | null;
}

export interface DriverFilterOption {
    id: number;
    first_name: string;
    first_lastname: string;
    identification_number: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servicios',
        href: services.index().url,
    },
];

const baseServiceFilters: FilterDefinition[] = [
    {
        name: 'service_status',
        label: 'Estado',
        options: [
            { value: 'open', label: 'Abierto' },
            { value: 'closed', label: 'Cerrado' },
        ],
    },
    {
        name: 'payment_method',
        label: 'Método de pago',
        options: [
            { value: 'cash', label: 'Efectivo' },
            { value: 'credit', label: 'Crédito' },
            { value: 'transfer', label: 'Transferencia' },
        ],
    },
];

function contractFilterLabel(contract: ContractFilterOption): string {
    const tp = contract.third_party;
    const customer = tp
        ? tp.is_natural_person
            ? [tp.first_name, tp.first_lastname].filter(Boolean).join(' ')
            : (tp.company_name ?? '')
        : '';
    return customer
        ? `${contract.contract_number} · ${customer}`
        : contract.contract_number;
}

function driverFilterLabel(driver: DriverFilterOption): string {
    const name = [driver.first_name, driver.first_lastname]
        .filter(Boolean)
        .join(' ');
    return `${name} (${driver.identification_number})`;
}

function vehicleFilterLabel(vehicle: VehicleOption): string {
    const parts = [vehicle.plate];
    const model = [vehicle.brand, vehicle.line].filter(Boolean).join(' ');
    if (model) {
        parts.push(model);
    }
    return parts.join(' · ');
}

function municipalityFilterLabel(municipality: MunicipalityOption): string {
    const dept = municipality.department?.name;
    return dept ? `${municipality.name} (${dept})` : municipality.name;
}

function startOfWeekIso(): string {
    const d = new Date();
    const dayIdx = d.getDay(); // 0 = Sunday
    const mondayOffset = dayIdx === 0 ? -6 : 1 - dayIdx;
    d.setDate(d.getDate() + mondayOffset);
    return d.toISOString().slice(0, 10);
}

function endOfWeekIso(): string {
    const d = new Date();
    const dayIdx = d.getDay();
    const sundayOffset = dayIdx === 0 ? 0 : 7 - dayIdx;
    d.setDate(d.getDate() + sundayOffset);
    return d.toISOString().slice(0, 10);
}

export default function ServicesIndex({
    services: paginatedServices,
    filterContracts,
    filterDrivers,
    filterVehicles,
    filterMunicipalities,
}: {
    services: PaginatedData<Service>;
    filterContracts: ContractFilterOption[];
    filterDrivers: DriverFilterOption[];
    filterVehicles: VehicleOption[];
    filterMunicipalities: MunicipalityOption[];
}) {
    'use no memo';
    const {
        table,
        paginatedData,
        search,
        setSearch,
        loading,
        onNavigate,
        onPerPageChange,
        activeFilters,
        setFilter,
        setFilters,
        clearFilters,
    } = useServerTable({ data: paginatedServices, columns });

    const sharedConfig = usePage().props.config as
        | { operation_tz?: string }
        | undefined;
    const operationTz = sharedConfig?.operation_tz ?? 'America/Bogota';

    // Fold the dynamic server-shipped option lists into the existing
    // toolbar FilterDefinition[] shape so Contrato / Conductor /
    // Vehículo / Municipio Origen / Municipio Destino render as the
    // same multi-select DataTableFacetedFilter buttons that already
    // power Estado + Método de pago.
    const serviceFilters = useMemo<FilterDefinition[]>(
        () => [
            ...baseServiceFilters,
            {
                name: 'contract_id',
                label: 'Contrato',
                options: filterContracts.map((c) => ({
                    value: String(c.id),
                    label: contractFilterLabel(c),
                })),
            },
            {
                name: 'driver_id',
                label: 'Conductor',
                options: filterDrivers.map((d) => ({
                    value: String(d.id),
                    label: driverFilterLabel(d),
                })),
            },
            {
                name: 'vehicle_id',
                label: 'Vehículo',
                options: filterVehicles.map((v) => ({
                    value: String(v.id),
                    label: vehicleFilterLabel(v),
                })),
            },
            {
                name: 'origin_municipality_id',
                label: 'Ciudad Origen',
                options: filterMunicipalities.map((m) => ({
                    value: String(m.id),
                    label: municipalityFilterLabel(m),
                })),
                capitalizeOptions: true,
            },
            {
                name: 'destination_municipality_id',
                label: 'Ciudad Destino',
                options: filterMunicipalities.map((m) => ({
                    value: String(m.id),
                    label: municipalityFilterLabel(m),
                })),
                capitalizeOptions: true,
            },
        ],
        [filterContracts, filterDrivers, filterVehicles, filterMunicipalities],
    );

    const dateFrom = activeFilters['date_from']?.[0] ?? '';
    const dateTo = activeFilters['date_to']?.[0] ?? '';

    // Header date-range filters for the datetime columns. Each maps to a
    // `<prefix>_from` / `<prefix>_to` pair handled by ServiceController's
    // dateRangeFilters() helper. The popover edits one field at a time, so
    // the back-to-back setFilter race documented below does not apply here.
    const datetimeRangeFilters = [
        { prefix: 'planned_start', label: 'Inicio planificado' },
        { prefix: 'planned_end', label: 'Fin planificado' },
        { prefix: 'actual_start', label: 'Inicio real' },
        { prefix: 'actual_end', label: 'Fin real' },
        { prefix: 'created', label: 'Creación' },
    ] as const;

    function makeRangeHandler(prefix: string) {
        const fromKey = `${prefix}_from`;
        const toKey = `${prefix}_to`;
        return ({ from, to }: { from: string; to: string }) => {
            const curFrom = activeFilters[fromKey]?.[0] ?? '';
            const curTo = activeFilters[toKey]?.[0] ?? '';
            if (from !== curFrom) {
                setFilter(fromKey, from ? [from] : []);
            }
            if (to !== curTo) {
                setFilter(toKey, to ? [to] : []);
            }
        };
    }

    function handleDateRangeChange({ from, to }: { from: string; to: string }) {
        // Only push the field that actually changed; setFilter fires a
        // server round-trip and back-to-back calls race the
        // useServerTable hook's currentParams cache (documented in
        // services-index-filter-expansion). The popover edits one
        // field at a time so this is fine.
        if (from !== dateFrom) {
            setFilter('date_from', from ? [from] : []);
        }
        if (to !== dateTo) {
            setFilter('date_to', to ? [to] : []);
        }
    }

    function applyPreset(preset: 'today' | 'this_week' | 'open_only') {
        if (preset === 'today') {
            const today = viewerToday(operationTz);
            setFilters({ date_from: [today], date_to: [today] });
        } else if (preset === 'this_week') {
            setFilters({
                date_from: [startOfWeekIso()],
                date_to: [endOfWeekIso()],
            });
        } else if (preset === 'open_only') {
            setFilter('service_status', ['open']);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servicios" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por origen, destino o grupo..."
                    filters={serviceFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    extraFilters={
                        <>
                            <DataTableDateRangeFilter
                                label="Rango de fechas"
                                from={dateFrom}
                                to={dateTo}
                                onChange={handleDateRangeChange}
                                fromInputId="services-filter-date-from"
                                toInputId="services-filter-date-to"
                            />
                            {datetimeRangeFilters.map(({ prefix, label }) => (
                                <DataTableDateRangeFilter
                                    key={prefix}
                                    label={label}
                                    from={activeFilters[`${prefix}_from`]?.[0] ?? ''}
                                    to={activeFilters[`${prefix}_to`]?.[0] ?? ''}
                                    onChange={makeRangeHandler(prefix)}
                                    fromInputId={`services-filter-${prefix}-from`}
                                    toInputId={`services-filter-${prefix}-to`}
                                />
                            ))}
                        </>
                    }
                    leadingActions={
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                onClick={() => applyPreset('today')}
                            >
                                Hoy
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                onClick={() => applyPreset('this_week')}
                            >
                                Esta semana
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                onClick={() => applyPreset('open_only')}
                            >
                                Pendientes de cerrar
                            </Button>
                        </>
                    }
                    actions={
                        <Can permission={Permission.CREATE_SERVICES}>
                            <Button asChild size="sm">
                                <Link
                                    href={services.create().url}
                                    aria-label="Crear Servicio"
                                >
                                    <Plus className="size-4" />
                                    <ToolbarLabel>Crear Servicio</ToolbarLabel>
                                </Link>
                            </Button>
                        </Can>
                    }
                />
            </div>
        </AppLayout>
    );
}
