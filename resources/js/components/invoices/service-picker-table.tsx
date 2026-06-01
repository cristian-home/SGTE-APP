import { AlertTriangle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dateFormatter, parseDueDate } from '@/lib/document-status';

export interface ServicePickerRow {
    id: number;
    service_number: string;
    service_date: string | null;
    unit_value: string | number | null;
    quantity: number | null;
    service_status: string;
    vehicle?: { id: number; plate: string } | null;
    driver?: {
        id: number;
        first_name: string;
        first_lastname: string;
    } | null;
    contract?: { id: number; contract_number: string } | null;
    service_incidents?: Array<{
        id: number;
        affects_billing: boolean;
        additional_value: string | number | null;
    }>;
    billing_groups?: string[] | null;
}

export const JUSTIFICATION_MIN = 10;

const currencyFormatter = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
});

function formatDate(date: string | null): string {
    const parsed = parseDueDate(date);
    if (parsed === null) {
        return '—';
    }
    return dateFormatter.format(parsed);
}

function driverName(driver: ServicePickerRow['driver']): string {
    if (!driver) return '—';
    return (
        [driver.first_name, driver.first_lastname]
            .filter(Boolean)
            .join(' ')
            .trim() || '—'
    );
}

function estimatedValue(row: ServicePickerRow): string {
    if (row.unit_value === null || row.quantity === null) {
        return '—';
    }
    const total = Number(row.unit_value) * Number(row.quantity);
    if (Number.isNaN(total)) {
        return '—';
    }
    return currencyFormatter.format(total);
}

function billingIncidentCount(row: ServicePickerRow): number {
    return (row.service_incidents ?? []).filter((i) => i.affects_billing)
        .length;
}

/**
 * Compute the per-service billable amount the picker shows in the
 * "Valor estimado" column: unit_value * quantity + Σ additional_value
 * of billing-affecting incidents. Exported so the parent (e.g. invoice
 * create form) can sum the selected rows for an auto-total preview.
 */
export function rowBillableTotal(row: ServicePickerRow): number {
    const base =
        row.unit_value === null || row.quantity === null
            ? 0
            : Number(row.unit_value) * Number(row.quantity);
    const incidents = (row.service_incidents ?? [])
        .filter((i) => i.affects_billing)
        .reduce((acc, i) => acc + Number(i.additional_value ?? 0), 0);
    const total = base + incidents;
    return Number.isFinite(total) ? total : 0;
}

interface ServicePickerTableProps {
    candidates: ServicePickerRow[];
    blockedCandidates?: ServicePickerRow[];
    /**
     * Services already linked to the parent record (e.g. invoice
     * being edited). Rendered in a top section with pre-ticked
     * checkboxes; un-ticking translates into a detach on submit when
     * the parent uses set-final semantics.
     */
    attachedCandidates?: ServicePickerRow[];
    selectedIds: number[];
    onSelectedIdsChange: (ids: number[]) => void;
    showBlocked: boolean;
    onShowBlockedChange: (value: boolean) => void;
    justification: string;
    onJustificationChange: (value: string) => void;
    justificationError?: string;
    serviceIdsError?: string;
    search: string;
    onSearchChange: (value: string) => void;
    /** Optional id prefix to namespace input ids when used inside another form. */
    idPrefix?: string;
}

export default function ServicePickerTable({
    candidates,
    blockedCandidates = [],
    attachedCandidates = [],
    selectedIds,
    onSelectedIdsChange,
    showBlocked,
    onShowBlockedChange,
    justification,
    onJustificationChange,
    justificationError,
    serviceIdsError,
    search,
    onSearchChange,
    idPrefix = '',
}: ServicePickerTableProps) {
    const id = (name: string) => (idPrefix ? `${idPrefix}_${name}` : name);

    const [groupFilter, setGroupFilter] = useState<string>('');

    const blockedIds = useMemo(
        () => new Set(blockedCandidates.map((r) => r.id)),
        [blockedCandidates],
    );

    const hasSelectedBlocked = useMemo(
        () => selectedIds.some((rowId) => blockedIds.has(rowId)),
        [selectedIds, blockedIds],
    );

    // Union of billing-group tags across all candidate buckets, used
    // to populate the local "Grupo" filter. Derived from data so
    // operators only see groups that actually apply to the visible
    // services (no dead options).
    const availableGroups = useMemo(() => {
        const set = new Set<string>();
        for (const row of [
            ...candidates,
            ...blockedCandidates,
            ...attachedCandidates,
        ]) {
            for (const tag of row.billing_groups ?? []) {
                set.add(tag);
            }
        }
        return Array.from(set.values()).sort((a, b) => a.localeCompare(b));
    }, [candidates, blockedCandidates, attachedCandidates]);

    const filter = (rows: ServicePickerRow[]) => {
        const term = search.trim().toLowerCase();
        const tag = groupFilter === '' ? null : groupFilter;
        if (!term && tag === null) return rows;
        return rows.filter((row) => {
            if (tag !== null) {
                const has = (row.billing_groups ?? []).includes(tag);
                if (!has) return false;
            }
            if (!term) return true;
            const consecutive = (row.service_number ?? '').toLowerCase();
            const plate = (row.vehicle?.plate ?? '').toLowerCase();
            const contract = (
                row.contract?.contract_number ?? ''
            ).toLowerCase();
            const first = (row.driver?.first_name ?? '').toLowerCase();
            const last = (row.driver?.first_lastname ?? '').toLowerCase();
            return (
                consecutive.includes(term) ||
                plate.includes(term) ||
                contract.includes(term) ||
                first.includes(term) ||
                last.includes(term)
            );
        });
    };

    const filteredClean = useMemo(
        () => filter(candidates),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [candidates, search, groupFilter],
    );
    const filteredBlocked = useMemo(
        () => filter(blockedCandidates),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [blockedCandidates, search, groupFilter],
    );
    const filteredAttached = useMemo(
        () => filter(attachedCandidates),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [attachedCandidates, search, groupFilter],
    );

    const allCleanSelected =
        filteredClean.length > 0 &&
        filteredClean.every((r) => selectedIds.includes(r.id));

    function toggleOne(rowId: number) {
        if (selectedIds.includes(rowId)) {
            onSelectedIdsChange(selectedIds.filter((x) => x !== rowId));
        } else {
            onSelectedIdsChange([...selectedIds, rowId]);
        }
    }

    function toggleAllClean() {
        if (allCleanSelected) {
            onSelectedIdsChange(
                selectedIds.filter(
                    (rowId) => !filteredClean.some((r) => r.id === rowId),
                ),
            );
        } else {
            onSelectedIdsChange(
                Array.from(
                    new Set([
                        ...selectedIds,
                        ...filteredClean.map((r) => r.id),
                    ]),
                ),
            );
        }
    }

    function handleShowBlockedChange(value: boolean) {
        onShowBlockedChange(value);
        if (!value) {
            // Hiding blocked rows also deselects them — avoids a confused
            // "where did my selection go" when the user toggles off.
            onSelectedIdsChange(
                selectedIds.filter((rowId) => !blockedIds.has(rowId)),
            );
        }
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-4">
                <Input
                    placeholder="Buscar por placa, contrato o conductor..."
                    value={search}
                    onChange={(e) => onSearchChange(e.target.value)}
                    className="max-w-md"
                />
                {availableGroups.length > 0 && (
                    <Select
                        value={groupFilter === '' ? 'all' : groupFilter}
                        onValueChange={(v) =>
                            setGroupFilter(v === 'all' ? '' : v)
                        }
                    >
                        <SelectTrigger
                            className="w-[200px]"
                            aria-label="Filtrar por grupo de facturación"
                        >
                            <SelectValue placeholder="Grupo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                Todos los grupos
                            </SelectItem>
                            {availableGroups.map((tag) => (
                                <SelectItem key={tag} value={tag}>
                                    {tag}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
                {blockedCandidates.length > 0 && (
                    <Label className="ml-auto flex items-center gap-2 text-sm font-normal text-muted-foreground">
                        <Switch
                            checked={showBlocked}
                            onCheckedChange={handleShowBlockedChange}
                            aria-label="Mostrar servicios con novedades facturables"
                        />
                        <span>
                            Mostrar servicios con novedades facturables (
                            {blockedCandidates.length})
                        </span>
                    </Label>
                )}
            </div>

            <div className="rounded-md border">
                {filteredAttached.length === 0 &&
                filteredClean.length === 0 &&
                !showBlocked ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Sin servicios candidatos.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={allCleanSelected}
                                        onCheckedChange={toggleAllClean}
                                        aria-label="Seleccionar todos"
                                    />
                                </TableHead>
                                <TableHead>Servicio</TableHead>
                                <TableHead>Vehículo</TableHead>
                                <TableHead>Conductor</TableHead>
                                <TableHead>Contrato</TableHead>
                                <TableHead className="text-right">
                                    Valor estimado
                                </TableHead>
                                <TableHead className="text-center">
                                    Novedades
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredAttached.length > 0 && (
                                <>
                                    <TableRow className="bg-muted/60 hover:bg-muted/60">
                                        <TableCell
                                            colSpan={7}
                                            className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Asociados actualmente — destilda
                                            para desvincular al guardar
                                        </TableCell>
                                    </TableRow>
                                    {filteredAttached.map((row) => {
                                        const incidents =
                                            billingIncidentCount(row);
                                        return (
                                            <TableRow
                                                key={`attached-${row.id}`}
                                                className="bg-emerald-500/5"
                                            >
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedIds.includes(
                                                            row.id,
                                                        )}
                                                        onCheckedChange={() =>
                                                            toggleOne(row.id)
                                                        }
                                                        aria-label={`Mantener servicio ${row.id} asociado`}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="font-mono tabular-nums">
                                                        {row.service_number}
                                                    </span>
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        {formatDate(
                                                            row.service_date,
                                                        )}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-mono">
                                                    {row.vehicle?.plate ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {driverName(row.driver)}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {row.contract
                                                        ?.contract_number ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {estimatedValue(row)}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    {incidents > 0 ? (
                                                        <Badge variant="secondary">
                                                            {incidents}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </>
                            )}
                            {filteredAttached.length > 0 &&
                                filteredClean.length > 0 && (
                                    <TableRow className="bg-muted/60 hover:bg-muted/60">
                                        <TableCell
                                            colSpan={7}
                                            className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Disponibles para asociar
                                        </TableCell>
                                    </TableRow>
                                )}
                            {filteredClean.map((row) => {
                                const incidents = billingIncidentCount(row);
                                return (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <Checkbox
                                                checked={selectedIds.includes(
                                                    row.id,
                                                )}
                                                onCheckedChange={() =>
                                                    toggleOne(row.id)
                                                }
                                                aria-label={`Seleccionar servicio ${row.id}`}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <span className="font-mono tabular-nums">
                                                {row.service_number}
                                            </span>
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                {formatDate(row.service_date)}
                                            </span>
                                        </TableCell>
                                        <TableCell className="font-mono">
                                            {row.vehicle?.plate ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {driverName(row.driver)}
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">
                                            {row.contract?.contract_number ??
                                                '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {estimatedValue(row)}
                                        </TableCell>
                                        <TableCell className="text-center">
                                            {incidents > 0 ? (
                                                <Badge variant="secondary">
                                                    {incidents}
                                                </Badge>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                            {showBlocked && filteredBlocked.length > 0 && (
                                <>
                                    <TableRow className="bg-muted/60 hover:bg-muted/60">
                                        <TableCell
                                            colSpan={7}
                                            className="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            Con novedades facturables —
                                            requieren justificación
                                        </TableCell>
                                    </TableRow>
                                    {filteredBlocked.map((row) => {
                                        const incidents =
                                            billingIncidentCount(row);
                                        return (
                                            <TableRow
                                                key={`blocked-${row.id}`}
                                                className="bg-amber-500/5"
                                                data-audit-row="blocked"
                                            >
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedIds.includes(
                                                            row.id,
                                                        )}
                                                        onCheckedChange={() =>
                                                            toggleOne(row.id)
                                                        }
                                                        aria-label={`Seleccionar servicio bloqueado ${row.id}`}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="font-mono tabular-nums">
                                                        {row.service_number}
                                                    </span>
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        {formatDate(
                                                            row.service_date,
                                                        )}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-mono">
                                                    {row.vehicle?.plate ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {driverName(row.driver)}
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {row.contract
                                                        ?.contract_number ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {estimatedValue(row)}
                                                </TableCell>
                                                <TableCell className="text-center">
                                                    <Badge variant="destructive">
                                                        {incidents} nov.
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </>
                            )}
                        </TableBody>
                    </Table>
                )}
            </div>

            {hasSelectedBlocked && (
                <div className="space-y-2">
                    <Alert variant="destructive">
                        <AlertTriangle className="size-4" />
                        <AlertTitle>Justificación obligatoria</AlertTitle>
                        <AlertDescription>
                            Está asignando uno o más servicios con novedades que
                            afectan la facturación. Indique por qué se factura
                            de todos modos.
                        </AlertDescription>
                    </Alert>
                    <Label htmlFor={id('override_justification')}>
                        Justificación *
                    </Label>
                    <textarea
                        id={id('override_justification')}
                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        value={justification}
                        onChange={(e) => onJustificationChange(e.target.value)}
                        placeholder="Ej: El cliente autorizó la facturación tras revisar la novedad."
                        minLength={JUSTIFICATION_MIN}
                        maxLength={1000}
                        aria-invalid={justificationError ? true : undefined}
                    />
                    {justificationError && (
                        <p className="text-sm text-destructive">
                            {justificationError}
                        </p>
                    )}
                </div>
            )}

            {serviceIdsError && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertDescription>{serviceIdsError}</AlertDescription>
                </Alert>
            )}
        </div>
    );
}
