import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import InvoiceForm, {
    type EligibleServicesPayload,
    type InvoiceFormData,
} from '@/components/invoices/invoice-form';
import { type ServicePickerRow } from '@/components/invoices/service-picker-table';
import { type ThirdPartyOption } from '@/components/third-parties/third-party-combobox';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePermissions } from '@/hooks/use-permissions';
import { parseDueDate } from '@/lib/document-status';
import type { Invoice } from '@/types/models';

/** Subset of an Invoice needed to pre-fill the form in edit mode. */
export type EditableInvoice = Pick<
    Invoice,
    | 'id'
    | 'invoice_number'
    | 'third_party_id'
    | 'total_value'
    | 'issue_date'
    | 'payment_status'
    | 'notes'
> & {
    services_count?: number;
    third_party?: ThirdPartyOption | null;
};

interface InvoiceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    mode: 'create' | 'edit';
    invoice?: EditableInvoice | null;
    thirdParties: ThirdPartyOption[];
    /** Preview del próximo número en create-mode. */
    nextInvoiceNumberPreview?: string;
}

const emptyData: InvoiceFormData = {
    third_party_id: '',
    invoice_number: '',
    total_value: '',
    issue_date: '',
    payment_status: 'pending',
    notes: '',
    service_ids: [],
    override_justification: '',
};

/** Project a wall-clock date string onto a `Y-m-d` value for date inputs. */
function toDateInput(value: string | null): string {
    const parsed = parseDueDate(value);
    if (parsed === null) {
        return '';
    }
    const y = parsed.getFullYear();
    const m = String(parsed.getMonth() + 1).padStart(2, '0');
    const d = String(parsed.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function dataFromInvoice(invoice: EditableInvoice): InvoiceFormData {
    return {
        // third_party_id puede ser null en facturas legacy/seed; mantenerlo
        // como '' para que el combobox arranque vacío y la validación
        // 'required' del backend lo capture si el usuario guarda sin elegir.
        third_party_id:
            invoice.third_party_id == null
                ? ''
                : String(invoice.third_party_id),
        invoice_number: invoice.invoice_number,
        total_value: String(invoice.total_value),
        issue_date: toDateInput(invoice.issue_date),
        payment_status: invoice.payment_status,
        notes: invoice.notes ?? '',
        service_ids: [],
        override_justification: '',
    };
}

export default function InvoiceDialog({
    open,
    onOpenChange,
    mode,
    invoice,
    thirdParties,
    nextInvoiceNumberPreview,
}: InvoiceDialogProps) {
    const { isSuperAdmin } = usePermissions();
    const { data, setData, post, put, processing, errors, clearErrors } =
        useForm<InvoiceFormData>({ ...emptyData });
    const [loadingEligible, setLoadingEligible] = useState(false);
    const [eligibleServices, setEligibleServices] =
        useState<EligibleServicesPayload | null>(null);
    const [attachedServices, setAttachedServices] = useState<
        ServicePickerRow[]
    >([]);
    // Track the in-flight request so a quick customer-switch cancels the
    // older fetch and the stale response can't overwrite the newer one.
    const abortRef = useRef<AbortController | null>(null);
    const attachedAbortRef = useRef<AbortController | null>(null);

    // Re-seed the form whenever the dialog identity changes. Inertia's
    // `setData`/`clearErrors` aren't React state setters, so this effect
    // is not flagged by the React Compiler (same pattern as UserDialog).
    useEffect(() => {
        if (!open) {
            // Cancel any in-flight requests when the dialog closes so
            // their responses don't reach an unmounted dialog (and to
            // be a good network citizen on rapid open/close).
            abortRef.current?.abort();
            attachedAbortRef.current?.abort();
            // Reset picker state when the dialog closes — a deliberate
            // reset-on-dependency-change tied to the open/close lifecycle.
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setEligibleServices(null);
            setAttachedServices([]);
            setLoadingEligible(false);
            return;
        }
        if (mode === 'edit' && invoice) {
            setData(dataFromInvoice(invoice));
            // En edit mode: pre-cargar attached + elegibles del cliente
            // para que el picker se hidrate desde el inicio. Los IDs
            // attached entran al data.service_ids como set inicial.
            fetchAttached(invoice.id);
            // Si la factura legacy no tiene cliente asignado, no
            // intentar el fetch (el endpoint requiere customer_id);
            // queda vacío hasta que el operador elija uno.
            if (invoice.third_party_id != null) {
                fetchEligible(String(invoice.third_party_id));
            } else {
                setEligibleServices(null);
            }
        } else {
            setData({ ...emptyData });
            setEligibleServices(null);
            setAttachedServices([]);
        }
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, invoice?.id]);

    function fetchAttached(invoiceId: number) {
        attachedAbortRef.current?.abort();
        const controller = new AbortController();
        attachedAbortRef.current = controller;
        const url = InvoiceController.attachedServices(invoiceId).url;
        fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((payload: { attachedCandidates: ServicePickerRow[] }) => {
                setAttachedServices(payload.attachedCandidates ?? []);
                // Set inicial de service_ids = los actualmente atachados.
                setData(
                    'service_ids',
                    (payload.attachedCandidates ?? []).map((r) => r.id),
                );
            })
            .catch((err) => {
                if (
                    err &&
                    typeof err === 'object' &&
                    'name' in err &&
                    (err as { name?: string }).name === 'AbortError'
                ) {
                    return;
                }
                setAttachedServices([]);
            });
    }

    function fetchEligible(customerId: string) {
        if (!customerId) {
            setEligibleServices(null);
            return;
        }
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;
        setLoadingEligible(true);
        const url = InvoiceController.eligibleServices({
            query: { customer_id: customerId },
        }).url;
        fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((payload: EligibleServicesPayload) => {
                setEligibleServices(payload);
            })
            .catch((err) => {
                if (
                    err &&
                    typeof err === 'object' &&
                    'name' in err &&
                    (err as { name?: string }).name === 'AbortError'
                ) {
                    return;
                }
                setEligibleServices({
                    cleanCandidates: [],
                    blockedCandidates: [],
                });
            })
            .finally(() => {
                if (abortRef.current === controller) {
                    setLoadingEligible(false);
                    abortRef.current = null;
                }
            });
    }

    // An invoice with attached services has a calculated total that the
    // form must not overwrite — lock the field when editing such a row.
    const servicesCount = invoice?.services_count ?? 0;
    const forceIncludeCustomer =
        mode === 'edit' && invoice?.third_party
            ? [invoice.third_party]
            : undefined;

    function handleThirdPartyChange(id: string) {
        if (mode !== 'create') return;
        if (!id) {
            abortRef.current?.abort();
            setEligibleServices(null);
            return;
        }
        fetchEligible(id);
    }

    function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        // This dialog owns its <form>; stop the submit event from bubbling
        // through the React tree to an ancestor <form>. See BUG-002.
        e.stopPropagation();
        if (mode === 'create') {
            post(InvoiceController.store().url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        } else if (invoice) {
            put(InvoiceController.update(invoice.id).url, {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[calc(100vh-4rem)] flex-col px-0 sm:max-w-4xl">
                <DialogHeader className="px-6">
                    <DialogTitle>
                        {mode === 'create' ? 'Crear Factura' : 'Editar Factura'}
                    </DialogTitle>
                    <DialogDescription>
                        {mode === 'create'
                            ? 'Complete los campos para registrar una nueva factura.'
                            : 'Actualice la información de la factura.'}
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-6 overflow-y-auto px-6 py-2">
                        <InvoiceForm
                            data={data}
                            setData={setData}
                            errors={errors}
                            thirdParties={thirdParties}
                            forceIncludeCustomer={forceIncludeCustomer}
                            idPrefix="dlg"
                            isTotalLocked={mode === 'edit' && servicesCount > 0}
                            servicesCount={servicesCount}
                            mode={mode}
                            eligibleServices={eligibleServices}
                            loadingEligible={loadingEligible}
                            onThirdPartyChange={handleThirdPartyChange}
                            nextInvoiceNumberPreview={nextInvoiceNumberPreview}
                            canEditInvoiceNumber={isSuperAdmin}
                            attachedServices={attachedServices}
                        />
                    </div>

                    <DialogFooter className="mt-4 gap-2 px-6">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {mode === 'create' ? 'Guardar' : 'Actualizar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
