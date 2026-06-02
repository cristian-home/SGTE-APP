import { Head } from '@inertiajs/react';
import { PlusIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DataTable } from '@/components/data-table';
import { ToolbarLabel } from '@/components/data-table/toolbar-label';
import { type MunicipalityOption } from '@/components/municipality-combobox';
import ThirdPartyDialog, {
    type EditableThirdParty,
} from '@/components/third-parties/third-party-dialog';
import { type DocumentTypeOption } from '@/components/third-parties/third-party-form';
import { Button } from '@/components/ui/button';
import { useServerTable } from '@/hooks/use-server-table';
import AppLayout from '@/layouts/app-layout';
import { type FacetCounts, withCounts } from '@/lib/facet-filter';
import thirdParties from '@/routes/third-parties';

import { columns, type ThirdPartyTableMeta } from './columns';

import type { BreadcrumbItem, FilterDefinition, PaginatedData } from '@/types';
import type { ThirdParty } from '@/types/models';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Terceros', href: thirdParties.index().url },
];

const STATIC_THIRD_PARTY_FILTERS: FilterDefinition[] = [
    {
        name: 'active',
        label: 'Estado',
        options: [
            { value: '1', label: 'Activo' },
            { value: '0', label: 'Inactivo' },
        ],
    },
    {
        name: 'is_natural_person',
        label: 'Tipo persona',
        options: [
            { value: '1', label: 'Natural' },
            { value: '0', label: 'Jurídica' },
        ],
    },
    {
        name: 'is_customer',
        label: 'Es cliente',
        options: [
            { value: '1', label: 'Sí' },
            { value: '0', label: 'No' },
        ],
    },
    {
        name: 'is_provider',
        label: 'Es proveedor',
        options: [
            { value: '1', label: 'Sí' },
            { value: '0', label: 'No' },
        ],
    },
];

// Negative-test marker: this index intentionally does NOT pass
// getRowClassName to <DataTable>. Third parties have no compliance
// axis (no expiring documents, no license state machine), so there's
// nothing to row-tint. If any future change tries to add row tinting
// here, that's a signal the abstraction is leaking — please open a
// discussion before adding it.

export default function ThirdPartiesIndex({
    thirdParties: paginatedThirdParties,
    municipalities,
    documentTypes,
    facetCounts,
}: {
    thirdParties: PaginatedData<ThirdParty>;
    municipalities: MunicipalityOption[];
    documentTypes: DocumentTypeOption[];
    facetCounts: FacetCounts;
}) {
    'use no memo';
    const [dialogOpen, setDialogOpen] = useState(false);
    const [dialogMode, setDialogMode] = useState<'create' | 'edit'>('create');
    const [selectedThirdParty, setSelectedThirdParty] =
        useState<EditableThirdParty | null>(null);

    function openCreate() {
        setSelectedThirdParty(null);
        setDialogMode('create');
        setDialogOpen(true);
    }

    function openEdit(thirdParty: EditableThirdParty) {
        setSelectedThirdParty(thirdParty);
        setDialogMode('edit');
        setDialogOpen(true);
    }

    const tableMeta: ThirdPartyTableMeta = useMemo(
        () => ({ onEdit: openEdit }),
        [],
    );

    const thirdPartyFilters = useMemo<FilterDefinition[]>(
        () => [
            ...STATIC_THIRD_PARTY_FILTERS,
            {
                name: 'municipality_id',
                label: 'Ciudad',
                options: withCounts(
                    municipalities.map((m) => ({
                        value: String(m.id),
                        label: m.department
                            ? `${m.name} (${m.department.name})`
                            : m.name,
                    })),
                    facetCounts.municipality_id,
                ),
                capitalizeOptions: true,
                sectioned: true,
            },
        ],
        [municipalities, facetCounts],
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
    } = useServerTable<ThirdParty>({
        data: paginatedThirdParties,
        columns,
        meta: tableMeta,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Terceros" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <DataTable
                    table={table}
                    paginatedData={paginatedData}
                    search={search}
                    onSearchChange={setSearch}
                    loading={loading}
                    onNavigate={onNavigate}
                    onPerPageChange={onPerPageChange}
                    searchPlaceholder="Buscar por identificación, nombre o razón social..."
                    filters={thirdPartyFilters}
                    activeFilters={activeFilters}
                    onFilterChange={setFilter}
                    onClearFilters={clearFilters}
                    actions={
                        <Button
                            onClick={openCreate}
                            size="sm"
                            aria-label="Crear Tercero"
                        >
                            <PlusIcon className="size-4" />
                            <ToolbarLabel>Crear Tercero</ToolbarLabel>
                        </Button>
                    }
                />
            </div>

            <ThirdPartyDialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                mode={dialogMode}
                thirdParty={selectedThirdParty}
                documentTypes={documentTypes}
                municipalities={municipalities}
            />
        </AppLayout>
    );
}
