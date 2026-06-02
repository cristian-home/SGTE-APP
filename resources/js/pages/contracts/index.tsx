import { Head } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import ContractDialog, {
    type EditableContract,
} from '@/components/contracts/contract-dialog';
import { contractRowTint } from '@/components/contracts/contract-period-pill';
import { DataTable } from '@/components/data-table';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import { type ThirdPartyOption } from '@/components/third-parties/third-party-combobox';
import { type DocumentTypeOption } from '@/components/third-parties/third-party-form';
import { Button } from '@/components/ui/button';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';
import { type FacetCounts, withCounts } from '@/lib/facet-filter';
import contracts from '@/routes/contracts';

import { columns, type ContractRow, type ContractTableMeta } from './columns';

import type { Row } from '@tanstack/react-table';
import type { BreadcrumbItem, FilterDefinition, PaginatedData } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Contratos', href: contracts.index().url },
];

const STATIC_CONTRACT_FILTERS: FilterDefinition[] = [
    {
        name: 'contract_status',
        label: 'Estado',
        options: [
            { value: 'vigente', label: 'Vigente' },
            { value: 'por_vencer', label: 'Por vencer (≤60 días)' },
            { value: 'vencido', label: 'Vencido' },
            { value: 'inactivo', label: 'Inactivo' },
        ],
    },
    {
        name: 'contract_object',
        label: 'Objeto',
        options: [
            { value: 'business', label: 'Empresarial' },
            { value: 'tourism', label: 'Turismo' },
            { value: 'health', label: 'Salud' },
            { value: 'occasional', label: 'Ocasional' },
        ],
    },
    {
        name: 'active',
        label: 'Activo',
        options: [
            { value: '1', label: 'Sí' },
            { value: '0', label: 'No' },
        ],
    },
    {
        name: 'is_generic',
        label: 'Genérico',
        options: [
            { value: '1', label: 'Sí' },
            { value: '0', label: 'No' },
        ],
    },
];

function rowTintFor(row: Row<ContractRow>): string | undefined {
    return contractRowTint(row.original);
}

export default function ContractsIndex({
    contracts: paginatedContracts,
    thirdParties,
    documentTypes = [],
    municipalities = [],
    facetCounts,
}: {
    contracts: PaginatedData<ContractRow>;
    thirdParties: ThirdPartyOption[];
    documentTypes?: DocumentTypeOption[];
    municipalities?: MunicipalityOption[];
    facetCounts: FacetCounts;
}) {
    'use no memo';
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogMode, setDialogMode] = useState<'create' | 'edit'>('create');
    const [selectedContract, setSelectedContract] =
        useState<EditableContract | null>(null);

    function openCreate() {
        setSelectedContract(null);
        setDialogMode('create');
        setDialogOpen(true);
    }

    function openEdit(contract: EditableContract) {
        setSelectedContract(contract);
        setDialogMode('edit');
        setDialogOpen(true);
    }

    const tableMeta: ContractTableMeta = useMemo(
        () => ({ onEdit: openEdit }),
        [],
    );

    const contractFilters = useMemo<FilterDefinition[]>(
        () => [
            ...STATIC_CONTRACT_FILTERS,
            {
                name: 'third_party_id',
                label: 'Cliente',
                options: withCounts(
                    thirdParties
                        .filter((tp) => tp.is_customer)
                        .map((tp) => ({
                            value: String(tp.id),
                            label: tp.is_natural_person
                                ? [tp.first_name, tp.first_lastname]
                                      .filter(Boolean)
                                      .join(' ') || '—'
                                : (tp.company_name ?? '—'),
                        })),
                    facetCounts.third_party_id,
                ),
                sectioned: true,
            },
        ],
        [thirdParties, facetCounts],
    );

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
        clearFilters,
    } = useServerTable<ContractRow>({
        data: paginatedContracts,
        columns,
        meta: tableMeta,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contratos" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por número de contrato..."
                    filters={contractFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    getRowClassName={rowTintFor}
                    actions={
                        <Button
                            onClick={openCreate}
                            size="sm"
                            aria-label="Crear Contrato"
                        >
                            <PlusIcon className="size-4" />
                            <ToolbarLabel>Crear Contrato</ToolbarLabel>
                        </Button>
                    }
                />
            </div>

            <ContractDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                mode={dialogMode}
                contract={selectedContract}
                thirdParties={thirdParties}
                documentTypes={documentTypes}
                municipalities={municipalities}
            />
        </AppLayout>
    );
}
